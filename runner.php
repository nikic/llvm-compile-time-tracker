<?php

use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use GitWrapper\Exception\GitException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/common.php';
require __DIR__ . '/src/data_aggregation.php';

// Decouple runner from viewer configs.
const RUNNER_CONFIGS = [
    'stage1-O3',
    'stage1-ReleaseThinLTO',
    'stage1-ReleaseLTO-g',
    'stage1-O0-g',
    'stage1-aarch64-O3',
    'stage1-aarch64-O0-g',
    'stage2-O3',
    'stage2-O0-g',
];

// Time to sleep if there were no new commits
$sleepInterval = 5 * 60;
$commitsFile = __DIR__ . '/data/commits.json';
$ctmarkDir = '/tmp/llvm-test-suite-build/CTMark';
$configNum = 6;
$runs = [
    'stage1-O0-g' => 2,
    'stage2-O0-g' => 2,
];
$llvmTimeout = 20 * 60; // 20 minutes
$benchTimeout = 5 * 60; // 5 minutes
$fetchTimeout = 45; // 45 seconds

$firstCommit = '36c1e568bb4f8e482e3f713c8cb9460c5cf19863';
$buildAfterCommit = '366ff3a89880139a132fe2738f36b39c89f5333e';
$branchPatterns = [
    '~^[^/]+/perf/.*~',
    '~^origin/main$~',
    '~^origin/release/(?:1[1-9]|2\d).x$~',
];

$gitWrapper = new GitWrapper();
$repo = $gitWrapper->workingCopy(__DIR__ . '/llvm-project');
$dataRepo = $gitWrapper->workingCopy(__DIR__ . '/data');
$stddevs = new StdDevManager();

// We --prune remote branches, but don't want old experiments in the commits file
// to be removed. For this reason, load the old data and overwrite the data, thus
// not touching branches that have been removed upstream.
$branchCommits = json_decode(file_get_contents($commitsFile), true);
$remotes = [];
while (true) {
    logInfo("Fetching remotes");
    foreach (explode("\n", rtrim($repo->remote())) as $remote) {
        $remotes[$remote] ??= new RemoteInfo();
    }
    updateLastCommitDates($remotes, $branchCommits);
    $now = new DateTime();
    uasort($remotes, function(RemoteInfo $r1, RemoteInfo $r2) use ($now) {
        return $r1->getScore($now) <=> $r2->getScore($now);
    });
    $fetchStart = time();
    foreach ($remotes as $remote => $info) {
        logInfo("Fetching $remote with score {$info->getScore($now)}");
        try {
            $repo->fetch($remote, ['prune' => true]);
            $info->lastFetch = $now;
        } catch (GitException $e) {
            logError($e->getMessage());
        }
        if (time() - $fetchStart > $fetchTimeout) {
            break;
        }
    }

    // Redoing all this work might get inefficient at some point...
    logInfo("Fetching commits");
    $branches = getRelevantBranches($repo, $branchPatterns);
    foreach ($branches as $branch) {
        $branchCommits[$branch] = getBranchCommits(
            $repo, $branch, $firstCommit, $branchCommits[$branch] ?? []);
    }

    logInfo("Finding work item");
    $workItem = getWorkItem($branchCommits, $stddevs, $buildAfterCommit);
    if ($workItem === null) {
        // Wait before checking for a new commit.
        sleep($sleepInterval);
        continue;
    }

    $hash = $workItem->hash;
    $configs = $workItem->configs;
    $reason = $workItem->reason;
    $hashDir = getDirForHash(CURRENT_DATA_DIR, $hash);
    logInfo("Building $hash. Reason: $reason");

    $repo->checkout($hash);
    @mkdir($hashDir, 0755, true);

    testHash($hash, $configs, $configNum, $runs, $llvmTimeout, $benchTimeout, $ctmarkDir);
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

function testHash(
        string $hash, array $configs,
        int $configNum, array $runs,
        int $llvmTimeout, int $benchTimeout,
        string $ctmarkDir) {
    $stage1Stats = buildStage($hash, 1, $llvmTimeout);
    if (null === $stage1Stats) {
        return null;
    }

    $stage2Stats = buildStage($hash, 2, $llvmTimeout);
    if (null === $stage2Stats) {
        return null;
    }

    [$stage2Stats, $ninjaLog] = parseStage2Stats($stage2Stats, '/tmp/llvm-project-build-stage2');

    $stats = [];
    $summary = new Summary($configNum, $stage1Stats, $stage2Stats, []);
    foreach ($configs as $config) {
        $rawDatas = [];
        $numRuns = $runs[$config] ?? 1;
        for ($run = 1; $run <= $numRuns; $run++) {
            logInfo("Building $config configuration (run $run)");
            try {
                [$stage, $realConfig] = explode('-', $config, 2);
                $arch = '';
                if (str_starts_with($realConfig, 'aarch64-')) {
                    $realConfig = str_replace('aarch64-', '', $realConfig);
                    $arch = 'aarch64';
                }
                // Use our own timeit.sh script.
                copy(__DIR__ . '/timeit.sh', __DIR__ . '/llvm-test-suite/tools/timeit.sh');
                runBuildCommand("./build_llvm_test_suite.sh $realConfig $stage $arch", $benchTimeout);
            } catch (CommandException $e) {
                writeError($hash, $e);
                // Skip this config, but test others.
                continue 2;
            }
            $rawDatas[] = readRawData($ctmarkDir);
        }

        $data = $runs > 1 ? averageRawData($rawDatas) : $rawDatas[0];
        $stats[$config] = $data;
        $summary->data[$config] = summarizeData($data);
    }

    writeSummaryForHash($hash, $summary);
    writeStatsForHash($hash, $stats);
    writeReducedNinjaLog($hash, $ninjaLog);
}

function buildStage(string $hash, int $stage, int $llvmTimeout): ?array {
    try {
        logInfo("Building stage$stage clang");
        $startTime = microtime(true);
        runBuildCommand("./build_llvm_project_stage$stage.sh", $llvmTimeout);
        $buildTime = microtime(true) - $startTime;
    } catch (CommandException $e) {
        writeError($hash, $e);
        return null;
    }

    // Gather statistics on the size of the clang binary.
    $stats = computeSizeStatsForObject("/tmp/llvm-project-build-stage$stage/bin/clang");
    $stats['wall-time'] = $buildTime;
    return $stats;
}

function writeReducedNinjaLog(string $hash, array $log): void {
    $result = '';
    foreach ($log as $elems) {
        $result .= implode("\t", $elems) . "\n";
    }
    $file = getDirForHash(CURRENT_DATA_DIR, $hash) . "/stage2log.gz";
    file_put_contents($file, gzencode($result, 9));
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

function getLastLines(string $str, int $numLines): string {
    $lines = explode("\n", $str);
    $lines = array_slice($lines, -$numLines);
    return implode("\n", $lines);
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
        return "MESSAGE: " . $this->getMessage()
             . "\n\nSTDOUT:\n" . getLastLines($this->stdout, 200)
             . "\n\nSTDERR:\n" . getLastLines($this->stderr, 200);
    }
}

function runCommand(string $command, ?int $timeout = null): void {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout($timeout);
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

function runBuildCommand(string $command, int $timeout): void {
    try {
        runCommand('sudo -u lctt-runner ' . $command, $timeout);
    } catch (ProcessTimedOutException $e) {
        // Kill ninja, which should kill any hanging clang/ld processes.
        try {
            runCommand("killall ninja clang++");
        } catch (CommandException $_) {
            /* We don't care if there was nothing to kill. */
        }
        $process = $e->getProcess();
        throw new CommandException(
            $e->getMessage(), $process->getOutput(), $process->getErrorOutput()
        );
    }
}

function getParsedLog(GitWorkingCopy $repo, string $branch, string $baseCommit) {
    $log = $repo->log(
        '--pretty=format:%H;%an;%cI;%s',
        '--reverse',
        '--first-parent',
        "$baseCommit^..$branch");
    $lines = explode("\n", $log);

    $parsedLog = [];
    foreach ($lines as $line) {
        $parts = explode(';', $line, 4);
        $parsedLog[] = [
            "hash" => $parts[0],
            "author_name" => $parts[1],
            "commit_date" => $parts[2],
            "subject" => $parts[3]
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

function getBranchCommits(
    GitWorkingCopy $repo, string $branch, string $firstCommit, array $prevCommits
): array {
    // Merge base calculations are expensive. If the HEAD commit of the branch did not change,
    // reuse the previous result.
    if (null !== $key = array_key_last($prevCommits)) {
        $prevLastCommit = $prevCommits[$key]['hash'];
        $curLastCommit = trim($repo->run('rev-parse', [$branch]));
        if ($prevLastCommit === $curLastCommit) {
            return $prevCommits;
        }
    }

    if ($branch === 'origin/main') {
        return getParsedLog($repo, $branch, $firstCommit);
    }
    $mergeBase = trim($repo->run('merge-base', [$branch, 'origin/main']));
    $commits = getParsedLog($repo, $branch, $mergeBase);
    // Ignore branches to which a large number of new commits was pushed,
    // this was likely a mistake. Only add the last commit to indicate that
    // the branch has been processed at all.
    if (count($commits) > count($prevCommits) + 100) {
        return [end($commits)];
    }
    return $commits;
}

function haveData(string $hash): bool {
    return file_exists(getDirForHash(CURRENT_DATA_DIR, $hash) . '/summary.json');
}

function haveError(string $hash): bool {
    return file_exists(getDirForHash(CURRENT_DATA_DIR, $hash) . '/error');
}

function writeError(string $hash, Exception $e): void {
    echo $e->getMessage(), "\n";
    file_put_contents(
        getDirForHash(CURRENT_DATA_DIR, $hash) . '/error',
        $e->getDebugOutput(), FILE_APPEND);
}

function getMissingConfigs(string $hash): array {
    if (haveError($hash) || haveData($hash)) {
        return [];
    }

    return RUNNER_CONFIGS;
}

class WorkItem {
    public function __construct(
        public string $hash,
        public array $configs,
        public string $reason,
    ) {}
}

class WorkItemCandidate {
    public function __construct(
        public WorkItem $workItem,
        public string $branch,
        public DateTime $date,
    ) {}
}

function getHeadWorkItem(array $commits): ?WorkItem {
    $head = $commits[count($commits) - 1];
    $hash = $head['hash'];
    if ($configs = getMissingConfigs($hash)) {
        return new WorkItem($hash, $configs, "New HEAD commit");
    }
    return null;
}

function getNewestWorkItemCandidate(
    string $branch, array $commits
): ?WorkItemCandidate {
    // Process newer commits first.
    foreach (array_reverse($commits) as $commit) {
        $hash = $commit['hash'];
        if ($configs = getMissingConfigs($hash)) {
            return new WorkItemCandidate(
                new WorkItem($hash, $configs, "Newest commit"),
                $branch, new DateTime($commit['commit_date'])
            );
        }
    }
    return null;
}

class MissingRange {
    public $hash1;
    public $hash2;
    public $missingHashes;

    public function __construct(string $hash1, string $hash2, array $missingHashes) {
        $this->hash1 = $hash1;
        $this->hash2 = $hash2;
        $this->missingHashes = $missingHashes;
    }

    public function getNonErrorSize(): int {
        $size = 0;
        foreach ($this->missingHashes as list(, $haveError)) {
            if (!$haveError) {
                $size++;
            }
        }
        return $size;
    }

    public function getBisectWorkItem(string $reason): WorkItem {
        $ranges = $this->getErrorFreeRanges();
        $largestRange = null;
        foreach ($ranges as $range) {
            if ($largestRange === null || count($range) > count($largestRange)) {
                $largestRange = $range;
            }
        }

        $idx = intdiv(count($largestRange), 2);
        $hash = $largestRange[$idx];
        return new WorkItem($hash, getMissingConfigs($hash), $reason);
    }

    private function getErrorFreeRanges(): array {
        $ranges = [];
        $curRange = [];
        foreach ($this->missingHashes as list($hash, $haveError)) {
            if (!$haveError) {
                $curRange[] = $hash;
            } else if (!empty($curRange)) {
                $ranges[] = $curRange;
                $curRange = [];
            }
        }
        if (!empty($curRange)) {
            $ranges[] = $curRange;
        }
        return $ranges;
    }
}

function getMissingRanges(array $commits): array {
    $lastPresentHash = null;
    $missingHashes = [];
    $missingRanges = [];
    foreach (array_reverse($commits) as $commit) {
        $hash = $commit['hash'];
        $haveError = haveError($hash);
        if ($haveError || getMissingConfigs($hash)) {
            $missingHashes[] = [$hash, $haveError];
        } else {
            if ($missingHashes && $lastPresentHash) {
                $missingRange = new MissingRange($lastPresentHash, $hash, $missingHashes);
                if ($missingRange->getNonErrorSize() !== 0) {
                    $missingRanges[] = $missingRange;
                }
            }

            $missingHashes = [];
            $lastPresentHash = $hash;
        }
    }
    return $missingRanges;
}

function getInterestingness(
    Summary $summary1, Summary $summary2, string $config, StdDevManager $stddevs
): ?float {
    $sigma = 5;
    $stat = 'instructions:u';
    $configNum = $summary2->configNum;
    if ($config === 'stage2-clang') {
        $value1 = $summary1->stage2Stats[$stat];
        $value2 = $summary2->stage2Stats[$stat];
        $diff = abs($value1 - $value2);
        $stddev = $stddevs->getBenchStdDev($configNum, 'build', 'stage2-clang', $stat);
        if ($stddev !== null && $stddev !== 0.0) {
            $interestingness = $diff / $stddev;
            if ($interestingness > $sigma) {
                return $interestingness;
            }
        }
        return null;
    }

    $data1 = $summary1->getConfig($config);
    $data2 = $summary2->getConfig($config);
    if (!$data1 || !$data2) {
        return null;
    }

    $maxInterestingness = null;
    foreach (['instructions:u'] as $stat) {
        foreach ($data1 as $bench => $stats1) {
            $stats2 = $data2[$bench];
            $value1 = $stats1[$stat];
            $value2 = $stats2[$stat];
            $diff = abs($value1 - $value2);
            $stddev = $stddevs->getBenchStdDev($configNum, $config, $bench, $stat);
            if ($stddev !== null && $stddev !== 0.0) {
                $interestingness = $diff / $stddev;
                if ($interestingness > $sigma &&
                    ($maxInterestingness === null || $interestingness > $maxInterestingness)) {
                    $maxInterestingness = $interestingness;
                }
            }
        }
    }
    return $maxInterestingness;
}

function getMostInterestingWorkItem(array $missingRanges, StdDevManager $stddevs): ?WorkItem {
    $mostInterestingRange = null;
    foreach ($missingRanges as $missingRange) {
        $summary1 = getSummaryForHash($missingRange->hash1);
        $summary2 = getSummaryForHash($missingRange->hash2);
        if (!$summary1 || !$summary2) {
            continue;
        }

        foreach ([...RUNNER_CONFIGS, 'stage2-clang'] as $config) {
            $interestingness = getInterestingness($summary1, $summary2, $config, $stddevs);
            if ($interestingness != null) {
                if ($mostInterestingRange === null || $interestingness > $mostInterestingRange[2]) {
                    $mostInterestingRange = [$missingRange, $config, $interestingness];
                }
            }
        }
    }
    if ($mostInterestingRange !== null) {
        return $mostInterestingRange[0]->getBisectWorkItem(
            "Bisecting interesting range for config " . $mostInterestingRange[1]);
    }
    return null;
}

function getBisectWorkItem(array $missingRanges): ?WorkItem {
    $largestMissingRange = null;
    foreach ($missingRanges as $missingRange) {
        if (!$largestMissingRange ||
                $missingRange->getNonErrorSize() > $largestMissingRange->getNonErrorSize()) {
            $largestMissingRange = $missingRange;
        }
    }
    if ($largestMissingRange) {
        return $largestMissingRange->getBisectWorkItem("Bisecting range");
    }
    return null;
}

function getRecentCommits(array $commits, ?string $buildAfterCommit): array {
    $recentCommits = [];
    $now = new DateTime();
    foreach ($commits as $commit) {
        if ($commit['hash'] === $buildAfterCommit) {
            $recentCommits = [];
            continue;
        }

        $date = new DateTime($commit['commit_date']);
        if ($date->diff($now)->days > 10) {
            continue;
        }
        $recentCommits[] = $commit;
    }
    return $recentCommits;
}

function getWorkItem(
    array $branchCommits, StdDevManager $stddevs, ?string $buildAfterCommit
): ?WorkItem {
    $candidates = [];
    foreach ($branchCommits as $branch => $commits) {
        // First process all non-main branches.
        if ($branch == 'origin/main') {
            continue;
        }

        // Build the newest missing commit.
        $candidate = getNewestWorkItemCandidate($branch, $commits);
        if ($candidate) {
            $candidates[] = $candidate;
        }
    }

    if (!empty($candidates)) {
        usort(
            $candidates,
            function(WorkItemCandidate $c1, WorkItemCandidate $c2): int {
                // Prefer non-origin branches.
                $isOrigin1 = str_starts_with($c1->branch, 'origin/');
                $isOrigin2 = str_starts_with($c2->branch, 'origin/');
                if ($isOrigin1 != $isOrigin2) {
                    return $isOrigin1 ? 1 : -1;
                }
                // Prefer older commits.
                return $c1->date <=> $c2->date;
            });
        return $candidates[0]->workItem;
    }

    // Then build the main branch.
    $branch = 'origin/main';
    $commits = $branchCommits[$branch];

    // Don't try to build too old commits.
    $commits = getRecentCommits($commits, $buildAfterCommit);
    if (empty($commits)) {
        return null;
    }

    /*$firstHash = $commits[0]['hash'];
    if ($configs = getMissingConfigs($firstHash)) {
        return new WorkItem($firstHash, $configs, 'First commit');
    }*/

    $missingRanges = getMissingRanges($commits);
    // Bisect ranges where a signficant change occurred,
    // to pin-point the exact revision.
    if ($workItem = getMostInterestingWorkItem($missingRanges, $stddevs)) {
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
    // Build the newest missing commit.
    if ($candidate = getNewestWorkItemCandidate($branch, $commits)) {
        return $candidate->workItem;
    }
    return null;
}

class RemoteInfo {
    public ?DateTime $lastCommit = null;
    public ?DateTime $lastFetch = null;

    public function getScore(DateTime $now): float {
        $score = 0.0;
        if ($this->lastCommit === null) {
            $score += 10.0;
        } else {
            $dt = $now->getTimestamp() - $this->lastCommit->getTimestamp();
            $score += max(min(log($dt / (60*60)), 10), 0);
        }
        if ($this->lastFetch === null) {
            $score -= 10.0;
        } else {
            $dt = $now->getTimestamp() - $this->lastFetch->getTimestamp();
            $score -= max(min($dt / (60*60), 10), 0);
        }
        return $score;
    }
}

function updateLastCommitDates(array $remotes, array $branchCommits) {
    foreach ($branchCommits as $branch => $commits) {
        $remote = strstr($branch, "/", before_needle: true);
        if ($remote === false || empty($commits) || !isset($remotes[$remote])) {
            continue;
        }
        $newestCommit = $commits[count($commits) - 1];
        $date = new DateTime($newestCommit['commit_date']);
        if ($remotes[$remote]->lastCommit === null ||
            $remotes[$remote]->lastCommit < $date
        ) {
            $remotes[$remote]->lastCommit = $date;
        }
    }
}
