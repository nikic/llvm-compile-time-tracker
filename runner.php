<?php

use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use GitWrapper\Exception\GitException;
use Symfony\Component\Process\Process;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/common.php';
require __DIR__ . '/src/data_aggregation.php';

// Time to sleep if there were no new commits
$sleepInterval = 5 * 60;
$commitsFile = __DIR__ . '/data/commits.json';

$firstCommit = '3860b2a0bd09291a276b0590939961dffe67fbb6';
$branchPatterns = [
    '~^[^/]+/perf/.*~',
    '~^origin/master$~',
];

$gitWrapper = new GitWrapper();
$repo = $gitWrapper->workingCopy(__DIR__ . '/llvm-project');
$dataRepo = $gitWrapper->workingCopy(__DIR__ . '/data');
$stddevs = getStddevData();

while (true) {
    logInfo("Fetching branches");
    try {
        $repo->fetch('--all');
    } catch (GitException $e) {
        // Log the failure, but carry on, we have plenty of old commits to build!
        logError($e->getMessage());
    }

    // Redoing all this work might get inefficient at some point...
    $branches = getRelevantBranches($repo, $branchPatterns);
    $branchCommits = [];
    foreach ($branches as $branch) {
        $branchCommits[$branch] = getBranchCommits($repo, $branch, $firstCommit);
    }

    logInfo("Finding work item");
    $workItem = getWorkItem($branchCommits, $stddevs);
    if ($workItem === null) {
        // Wait before checking for a new commit.
        sleep($sleepInterval);
        continue;
    }

    $hash = $workItem->hash;
    $configs = $workItem->configs;
    $reason = $workItem->reason;
    $hashDir = getDirForHash($hash);
    logInfo("Building $hash. Reason: $reason");

    $repo->checkout($hash);
    try {
        runCommand('./build_llvm_project.sh');
    } catch (CommandException $e) {
        echo $e->getMessage(), "\n";
        @mkdir($hashDir, 0755, true);
        file_put_contents($hashDir . '/error', $e->getDebugOutput());
        continue;
    }

    @mkdir($hashDir, 0755, true);

    // Gather statistics on the size of the clang binary.
    $sizeContents = shell_exec("size llvm-project-build/bin/clang");
    $sizeStats = parseSizeStats($sizeContents);
    file_put_contents($hashDir . '/size.json', json_encode($sizeStats, JSON_PRETTY_PRINT));

    foreach ($configs as $config) {
        logInfo("Building $config configuration");
        try {
            runCommand("./build_llvm_test_suite.sh $config");
        } catch (CommandException $e) {
            echo $e->getMessage(), "\n";
            file_put_contents($hashDir . '/error', $e->getDebugOutput());
            continue;
        }

        // TODO: Don't call into PHP here.
        runCommand("php aggregate_data.php $hashDir/$config");
    }

    file_put_contents($commitsFile, json_encode($branchCommits, JSON_PRETTY_PRINT));
    $dataRepo->add('.');
    $dataRepo->commit('-m', 'Add data');
    try {
        $dataRepo->push('origin', 'master');
    } catch (GitException $e) {
        // Log the failure, but carry on, we can push the data later.
        logError($e->getMessage());
    }
}

function logWithLevel(string $level, string $str) {
    $date = (new DateTime())->format('Y-m-d H:i:s.v');
    echo "[RUNNER] [$level] [$date] $str\n";
}

function logInfo(string $str) {
    logWithLevel("INFO", $str);
}

function logError(string $str) {
    logWithLevel("ERROR", $str);
}

class CommandException extends Exception {
    public $stdout;
    public $stderr;

    public function __construct(string $message, string $stdout, string $stderr) {
        parent::__construct($message);
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public function getDebugOutput(): string {
        return "STDOUT:\n" . substr($this->stdout, -2000)
             . "\n\nSTDERR:\n" . $this->stderr;
    }
}

function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(null);
    $exitCode = $process->run(function($type, $buffer) {
        echo $buffer;
    });
    if ($exitCode !== 0) {
        throw new CommandException(
            "Execution of \"$command\" failed",
            $process->getOutput(), $process->getErrorOutput()
        );
    }
}

function getParsedLog(GitWorkingCopy $repo, string $branch, string $baseCommit) {
    $log = $repo->log(
        '--pretty=format:%H;%an;%ae;%cI;%s',
        '--reverse',
        '--first-parent',
        "$baseCommit^..$branch");
    $lines = explode("\n", $log);

    $parsedLog = [];
    foreach ($lines as $line) {
        $parts = explode(';', $line, 5);
        $parsedLog[] = [
            "hash" => $parts[0],
            "author_name" => $parts[1],
            "author_email" => $parts[2],
            "commit_date" => $parts[3],
            "subject" => $parts[4]
        ];
    }
    return $parsedLog;
}

function getRelevantBranches(GitWorkingCopy $repo, array $branchPatterns): array {
    return array_filter($repo->getBranches()->remote(), function($branch) use($branchPatterns) {
        foreach ($branchPatterns as $pattern) {
            if (preg_match($pattern, $branch)) {
                return true;
            }
        }
        return false;
    });
}

function getBranchCommits(GitWorkingCopy $repo, string $branch, string $firstCommit): array {
    if ($branch === 'origin/master') {
        return getParsedLog($repo, $branch, $firstCommit);
    }
    $mergeBase = trim($repo->run('merge-base', [$branch, 'origin/master']));
    return getParsedLog($repo, $branch, $mergeBase);
}

function haveData(string $hash, string $config): bool {
    return is_dir(getDirForHash($hash) . '/' . $config);
}

function haveError(string $hash): bool {
    return file_exists(getDirForHash($hash) . '/error');
}

function getMissingConfigs(string $hash): array {
    if (haveError($hash)) {
        return [];
    }

    $configs = [];
    foreach (CONFIGS as $wantedConfig) {
        if (!haveData($hash, $wantedConfig)) {
            $configs[] = $wantedConfig;
        }
    }

    // The O0-g configuration was added later.
    // We do not want to run O0-g benchmarks for all old commits, so skip this case.
    if ($configs === ['O0-g']) {
        return [];
    }

    return $configs;
}

class WorkItem {
    public $hash;
    public $configs;
    public $reason;

    public function __construct(string $hash, array $configs, string $reason) {
        $this->hash = $hash;
        $this->configs = $configs;
        $this->reason = $reason;
    }
}

function getHeadWorkItem(array $commits): ?WorkItem {
    $head = $commits[count($commits) - 1];
    $hash = $head['hash'];
    if ($configs = getMissingConfigs($hash)) {
        return new WorkItem($hash, $configs, "New HEAD commit");
    }
    return null;
}

function getNewestWorkItem(array $commits): ?WorkItem {
    // Process newer commits first.
    foreach (array_reverse($commits) as $commit) {
        $hash = $commit['hash'];
        if ($configs = getMissingConfigs($hash)) {
            return new WorkItem($hash, $configs, "Newest commit");
        }
    }
    return null;
}

function getMissingRanges(array $commits): array {
    $lastPresentHash = null;
    $missingHashes = [];
    $missingRanges = [];
    foreach (array_reverse($commits) as $commit) {
        $hash = $commit['hash'];
        if (haveError($hash)) {
            continue;
        }

        if ($configs = getMissingConfigs($hash)) {
            $missingHashes[] = $hash;
        } else {
            if ($missingHashes && $lastPresentHash) {
                $missingRanges[] = [$lastPresentHash, $hash, $missingHashes];
            }
            $missingHashes = [];
            $lastPresentHash = $hash;
        }
    }
    return $missingRanges;
}

function getBisectWorkItemInRange(array $missingHashes, string $reason): WorkItem {
    $count = count($missingHashes);
    $idx = intdiv($count, 2);
    $hash = $missingHashes[$idx];
    return new WorkItem($hash, getMissingConfigs($hash), $reason);
}

function isInteresting(array $summary1, array $summary2, string $config, array $stddevs): bool {
    $sigma = 5;
    foreach (['instructions', 'max-rss'] as $stat) {
        foreach ($summary1 as $bench => $stats1) {
            $stats2 = $summary2[$bench];
            $value1 = $stats1[$stat];
            $value2 = $stats2[$stat];
            $diff = abs($value1 - $value2);
            $stddev = getStddev($stddevs, $config, $bench, $stat);
            if ($stddev !== null && $diff >= $sigma * $stddev) {
                return true;
            }
        }
    }
    return false;
}

function getInterestingWorkItem(array $missingRanges, array $stddevs): ?WorkItem {
    foreach ($missingRanges as list($hash1, $hash2, $missingHashes)) {
        foreach (CONFIGS as $config) {
            $summary1 = getSummary($hash1, $config);
            $summary2 = getSummary($hash2, $config);
            if (!$summary1 || !$summary2) {
                continue;
            }

            if (isInteresting($summary1, $summary2, $config, $stddevs)) {
                return getBisectWorkItemInRange($missingHashes,
                    "Bisecting interesting range for config $config");
            }
        }
    }
    return null;
}

function getBisectWorkItem(array $missingRanges): ?WorkItem {
    $largestMissingHashes = null;
    foreach ($missingRanges as list(, , $missingHashes)) {
        if (!$largestMissingHashes || count($missingHashes) > count($largestMissingHashes)) {
            $largestMissingHashes = $missingHashes;
        }
    }
    if ($largestMissingHashes) {
        return getBisectWorkItemInRange($largestMissingHashes, "Bisecting range");
    }
    return null;
}

function getRecentCommits(array $commits): array {
    $recentCommits = [];
    $now = new DateTime();
    foreach ($commits as $commit) {
        $date = new DateTime($commit['commit_date']);
        if ($date->diff($now)->days > 10) {
            continue;
        }
        $recentCommits[] = $commit;
    }
    return $recentCommits;
}

function getWorkItem(array $branchCommits, array $stddevs): ?WorkItem {
    foreach ($branchCommits as $branch => $commits) {
        if ($branch == 'origin/master') {
            // Don't try to build too old commits.
            $commits = getRecentCommits($commits);

            $missingRanges = getMissingRanges($commits);
            // Bisect ranges where a signficant change occurred,
            // to pin-point the exact revision.
            if ($workItem = getInterestingWorkItem($missingRanges, $stddevs)) {
                return $workItem;
            }
            // Build new commit.
            if ($workItem = getHeadWorkItem($commits)) {
                return $workItem;
            }
            // Bisect large ranges, so gaps are smaller.
            if ($workItem = getBisectWorkItem($missingRanges)) {
                return $workItem;
            }
        } else {
            // Build the newest missing commit.
            $workItem = getNewestWorkItem($commits);
            if ($workItem) {
                return $workItem;
            }
        }
    }
    return null;
}
