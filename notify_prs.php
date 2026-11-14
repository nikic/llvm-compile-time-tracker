<?php

// Post comments with compile-time results on PRs that were benchmarked via
// llvmbot/perf/pr-NNN branches.
//
// Usage: php notify_prs.php [--init] [--dry-run]
//
// --init:    Create the state file, marking all branches whose benchmarks have
//            already completed as notified, without posting any comments.
// --dry-run: Print comments that would be posted, without posting them or
//            updating the state file.
//
// The GitHub token is read from the file specified by the GITHUB_TOKEN_FILE
// environment variable.

error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/src/common.php';

const SITE_URL = 'https://llvm-compile-time-tracker.com';
const GITHUB_API_URL = 'https://api.github.com';
const GITHUB_REPO = 'llvm/llvm-project';
const PR_BRANCH_PATTERN = '~^llvmbot/perf/pr-(\d+)$~';
const STAT = 'instructions:u';
// Same threshold as used for highlighting on the website (see formatPerc()).
const SIGNIFICANCE_THRESHOLD = 3.0;
const POST_DELAY = 1; // Seconds between posted comments.

enum CommitStatus {
    case Pending;
    // Has data, but possibly also errors in some configurations.
    case Ok;
    // Only has errors.
    case Error;
}

$stateFile = __DIR__ . '/pr_comments.json';
$lockFile = __DIR__ . '/pr_comments.lock';
$commitsFile = CURRENT_DATA_DIR . '/commits.json';

$options = getopt('', ['init', 'dry-run'], $restIndex);
if ($restIndex !== count($argv)) {
    fwrite(STDERR, "Usage: php notify_prs.php [--init] [--dry-run]\n");
    exit(1);
}
$init = isset($options['init']);
$dryRun = isset($options['dry-run']);

$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    logInfo("Another instance is running");
    exit(0);
}

if (file_exists($stateFile)) {
    if ($init) {
        logError("State file $stateFile already exists");
        exit(1);
    }
    $state = json_decode(file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
} else {
    if (!$init) {
        // Refuse to run without state, to avoid commenting on all old PRs.
        logError("State file $stateFile does not exist, run with --init first");
        exit(1);
    }
    $state = [];
}

$token = null;
if (!$init && !$dryRun) {
    $token = readGitHubToken();
}

$stddevs = new StdDevManager();
$branchCommits = json_decode(file_get_contents($commitsFile), true, flags: JSON_THROW_ON_ERROR);
$numPosted = 0;
foreach ($branchCommits as $branch => $commits) {
    if (!preg_match(PR_BRANCH_PATTERN, $branch, $matches)) {
        continue;
    }

    $pr = (int) $matches[1];
    $head = end($commits)['hash'];
    if (($state[$pr]['head'] ?? null) === $head) {
        continue;
    }

    $statuses = [];
    foreach ($commits as $commit) {
        $statuses[$commit['hash']] = getCommitStatus($commit['hash']);
    }
    if (in_array(CommitStatus::Pending, $statuses, true)) {
        continue;
    }

    if ($init) {
        recordState($state, $pr, $head, 'init');
        continue;
    }

    if (count($commits) < 2) {
        // The runner only records the last commit if too many commits were pushed.
        logInfo("Skipping PR #$pr: No base commit for $head");
        if (!$dryRun) {
            recordState($state, $pr, $head, 'skipped');
            writeState($stateFile, $state);
        }
        continue;
    }

    $body = formatComment($commits, $statuses, $stddevs);
    if ($dryRun) {
        echo "=== Comment on PR #$pr ===\n$body\n\n";
        continue;
    }

    if ($numPosted !== 0) {
        sleep(POST_DELAY);
    }

    logInfo("Posting comment on PR #$pr for $head");
    [$httpStatus, $response] = postGitHubComment($token, $pr, $body);
    if ($httpStatus === 201) {
        recordState($state, $pr, $head, 'posted', $response['html_url'] ?? null);
        writeState($stateFile, $state);
        $numPosted++;
        continue;
    }

    $message = $response['message'] ?? 'Unknown error';
    if (isPermanentGitHubError($httpStatus, $message)) {
        logError("Failed to comment on PR #$pr (HTTP $httpStatus): $message");
        recordState($state, $pr, $head, 'failed');
        writeState($stateFile, $state);
        continue;
    }

    // Likely affects all requests (bad token, rate limit, GitHub outage).
    // Stop here and retry on the next run.
    logError("Aborting after error for PR #$pr (HTTP $httpStatus): $message");
    exit(1);
}

if ($init) {
    if (!$dryRun) {
        writeState($stateFile, $state);
    }
    logInfo("Initialized state with " . count($state) . " PRs");
}

function logWithLevel(string $level, string $str) {
    $date = (new DateTime())->format('Y-m-d H:i:s.v');
    echo "[NOTIFY] [$level] [$date] $str\n";
}

function logInfo(string $str) {
    logWithLevel("INFO", $str);
}

function logError(string $str) {
    logWithLevel("ERROR", $str);
}

function getCommitStatus(string $hash): CommitStatus {
    if (getPathForHash($hash, '/summary.json') !== null) {
        return CommitStatus::Ok;
    }
    if (hasBuildError($hash)) {
        return CommitStatus::Error;
    }
    return CommitStatus::Pending;
}

function recordState(
    array &$state, int $pr, string $head, string $status, ?string $commentUrl = null
): void {
    $state[$pr] = [
        'head' => $head,
        'status' => $status,
        'comment_url' => $commentUrl,
        'time' => (new DateTime())->format(DateTime::ATOM),
    ];
}

function writeState(string $stateFile, array $state): void {
    ksort($state);
    $tmpFile = $stateFile . '.tmp';
    file_put_contents($tmpFile, json_encode($state, JSON_PRETTY_PRINT));
    rename($tmpFile, $stateFile);
}

function formatPercDiff(float $newValue, float $oldValue): string {
    $perc = $oldValue !== 0.0 ? ($newValue / $oldValue - 1.0) * 100 : 0.0;
    $formatted = sprintf('%+.2f%%', $perc);
    // Avoid printing "-0.00%".
    return $formatted === '-0.00%' ? '+0.00%' : $formatted;
}

function formatComment(array $commits, array $statuses, StdDevManager $stddevs): string {
    $base = $commits[0]['hash'];
    $head = end($commits)['hash'];
    $compareUrl = SITE_URL . '/' . makeUrl('compare.php', [
        'from' => $base, 'to' => $head, 'stat' => STAT]);

    $body = "Compile-time results for $head (base $base):\n";

    $errorLines = [];
    foreach ($commits as $commit) {
        $hash = $commit['hash'];
        if (!hasBuildError($hash)) {
            continue;
        }
        $errorUrl = SITE_URL . '/' . makeUrl('show_error.php', ['commit' => $hash]);
        $what = $statuses[$hash] === CommitStatus::Error ? 'Failed to build' : 'Failed to build in some configurations';
        $errorLines[] = "> * $what: $hash ([log]($errorUrl))";
    }

    if ($statuses[$base] !== CommitStatus::Ok || $statuses[$head] !== CommitStatus::Ok) {
        // Base or head failed to build, so there is nothing to compare.
        return $body . "\n> [!WARNING]\n" . implode("\n", $errorLines) . "\n";
    }

    $body .= "$compareUrl\n";
    if ($errorLines) {
        $body .= "\n> [!WARNING]\n" . implode("\n", $errorLines) . "\n";
    }

    $fromSummary = getSummaryForHash($base);
    $toSummary = getSummaryForHash($head);
    $normalizer = null;
    if ($fromSummary->configNum !== $toSummary->configNum) {
        $normalizer = new ConfigNormalizer($fromSummary->configNum);
    }

    $rows = [];
    $numSignificant = 0;
    $addRow = function(
        string $label, string $config, string $bench, ?float $fromValue, ?float $toValue
    ) use ($fromSummary, $toSummary, $normalizer, $stddevs, &$rows, &$numSignificant): void {
        if ($fromValue === null || $toValue === null) {
            return;
        }

        if ($normalizer !== null) {
            $toValue = $normalizer->normalize(
                $toSummary->configNum, $config, $bench, STAT, $toValue);
        }
        // If values are normalized, use the stddev data of the config we're normalizing to.
        $stddev = $stddevs->getBenchStdDev($fromSummary->configNum, $config, $bench, STAT);
        $diff = formatPercDiff($toValue, $fromValue);
        if (getInterestingness($toValue - $fromValue, $stddev) >= SIGNIFICANCE_THRESHOLD) {
            $diff = "**$diff**";
            $numSignificant++;
        }
        $rows[] = "| $label | $diff |";
    };

    foreach (CONFIGS as $config) {
        $addRow($config, $config, 'geomean',
            $fromSummary->getConfig($config)['geomean'][STAT] ?? null,
            $toSummary->getConfig($config)['geomean'][STAT] ?? null);
    }
    $addRow('clang build', 'build', 'stage2-clang',
        $fromSummary->stage2Stats[STAT] ?? null,
        $toSummary->stage2Stats[STAT] ?? null);

    if (!$rows) {
        $body .= "\nNo common configurations to compare.\n";
        return $body;
    }

    if ($normalizer !== null) {
        $body .= "\n> [!WARNING]\n"
               . "> The server configuration changed between the base and head commits."
               . " Results were normalized on a best-effort basis and may be inaccurate.\n";
    }
    $body .= "\n| Config | " . STAT . " |\n|:--|--:|\n" . implode("\n", $rows) . "\n\n";
    if ($numSignificant === 0) {
        $body .= "No significant changes.";
    } else {
        $body .= "Changes in bold are likely significant. $numSignificant of " . count($rows)
               . " changed significantly.";
    }
    return $body . "\n";
}

function readGitHubToken(): string {
    $tokenFile = getenv('GITHUB_TOKEN_FILE');
    if ($tokenFile === false || $tokenFile === '') {
        logError("GITHUB_TOKEN_FILE is not set");
        exit(1);
    }
    if (!is_readable($tokenFile)) {
        logError("GitHub token file $tokenFile is not readable");
        exit(1);
    }
    if (fileperms($tokenFile) & 0077) {
        logError("GitHub token file $tokenFile should not be accessible by group or others");
        exit(1);
    }
    return trim(file_get_contents($tokenFile));
}

function postGitHubComment(string $token, int $pr, string $body): array {
    $ch = curl_init(GITHUB_API_URL . '/repos/' . GITHUB_REPO . "/issues/$pr/comments");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['body' => $body]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: llvm-compile-time-tracker',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        // Treat network errors like a server error, which will abort the run.
        logError("curl error: " . curl_error($ch));
        return [0, null];
    }
    $httpStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$httpStatus, json_decode($response, true)];
}

// Errors that only affect a specific PR (deleted, locked, ...), and will not
// go away when retrying. 404 is not included, because it may also indicate
// a token without access.
function isPermanentGitHubError(int $httpStatus, string $message): bool {
    if (in_array($httpStatus, [410, 422], true)) {
        return true;
    }
    return $httpStatus === 403 && str_contains(strtolower($message), 'locked');
}
