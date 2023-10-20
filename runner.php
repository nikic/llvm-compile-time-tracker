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
    'NewPM-O3',
    'NewPM-ReleaseThinLTO',
    'NewPM-ReleaseLTO-g',
    'NewPM-O0-g',
];

// Time to sleep if there were no new commits
$sleepInterval = 5 * 60;
$commitsFile = __DIR__ . '/data/commits.json';
$ctmarkDir = __DIR__ . '/llvm-test-suite-build/CTMark';
$configNum = 4;
$runs = 1;
$llvmTimeout = 60 * 60; // 60 minutes
$benchTimeout = 5 * 60; // 5 minutes

$firstCommit = '500a6c95ff63d0b1d68afe7b64fad4a569748aea';
//$firstCommit = '36c1e568bb4f8e482e3f713c8cb9460c5cf19863';
$branchPatterns = [
    //'~^[^/]+/perf/.*~',
    '~^origin/main$~',
    //'~^origin/release/1[1-9].x$~',
];

$gitWrapper = new GitWrapper();
$repo = $gitWrapper->workingCopy(__DIR__ . '/llvm-project');
$dataRepo = $gitWrapper->workingCopy(__DIR__ . '/data');
$stddevs = new StdDevManager();

// We --prune remote branches, but don't want old experiments in the commits file
// to be removed. For this reason, load the old data and overwrite the data, thus
// not touching branches that have been removed upstream.
$branchCommits = json_decode(file_get_contents($commitsFile), true);
while (true) {
    logInfo("Fetching branches");
    try {
        $repo->fetchAll(['prune' => true]);
    } catch (GitException $e) {
        // Log the failure, but carry on, we have plenty of old commits to build!
        logError($e->getMessage());
    }

    // Redoing all this work might get inefficient at some point...
    logInfo("Fetching commits");
    $branches = getRelevantBranches($repo, $branchPatterns);
    foreach ($branches as $branch) {
        $branchCommits[$branch] = getBranchCommits(
            $repo, $branch, $firstCommit, $branchCommits[$branch] ?? []);
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
        int $configNum, int $runs,
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

    $stage2Dir = __DIR__ . '/llvm-project-build-stage2';
    $ninjaLog = parseNinjaLog($stage2Dir . '/.ninja_log', $stage2Dir);
    $lastEndTime = $ninjaLog[array_key_last($ninjaLog)][1];
    $stage2Stats['wall-time-ninja'] = $lastEndTime / 1000.0;

    $stats = [];
    $summary = new Summary($configNum, $stage1Stats, $stage2Stats, []);
    foreach ($configs as $config) {
        $rawDatas = [];
        for ($run = 1; $run <= $runs; $run++) {
            logInfo("Building $config configuration (run $run)");
            try {
                if (strpos($config, 'NewPM-') === 0) {
                    $realConfig = substr($config, strlen('NewPM-'));
                } else {
                    throw new Exception('Missing config prefix');
                }
                runBuildCommand("./build_llvm_test_suite.sh $realConfig", $benchTimeout);
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
    $sizeContents = shell_exec("size llvm-project-build-stage$stage/bin/clang");
    $stats = parseSizeStats($sizeContents);
    $stats['wall-time'] = $buildTime;
    return $stats;
}

function writeReducedNinjaLog(string $hash, array $log): void {
    $result = '';
    foreach ($log as [$start, $end, $file]) {
        $result .= "$start\t$end\t$file\n";
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
             . "\n\nSTDOUT:\n" . getLastLines($this->stdout, 128)
             . "\n\nSTDERR:\n" . getLastLines($this->stderr, 128);
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
        runCommand($command, $timeout);
    } catch (ProcessTimedOutException $e) {
        // Kill ninja, which should kill any hanging clang/ld processes.
        try {
            runCommand("killall ninja");
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

function isInteresting(
    array $summary1, array $summary2, string $config, StdDevManager $stddevs
): bool {
    $sigma = 5;
    foreach (['instructions', 'max-rss'] as $stat) {
        foreach ($summary1 as $bench => $stats1) {
            $stats2 = $summary2[$bench];
            $value1 = $stats1[$stat];
            $value2 = $stats2[$stat];
            $diff = abs($value1 - $value2);
            $stddev = $stddevs->getBenchStdDev(/* TODO */ 2, $config, $bench, $stat);
            if ($stddev !== null && $diff >= $sigma * $stddev) {
                return true;
            }
        }
    }
    return false;
}

function getInterestingWorkItem(array $missingRanges, StdDevManager $stddevs): ?WorkItem {
    foreach ($missingRanges as $missingRange) {
        foreach (RUNNER_CONFIGS as $config) {
            $summary1 = getSummary($missingRange->hash1, $config);
            $summary2 = getSummary($missingRange->hash2, $config);
            if (!$summary1 || !$summary2) {
                continue;
            }

            if (isInteresting($summary1, $summary2, $config, $stddevs)) {
                return $missingRange->getBisectWorkItem(
                    "Bisecting interesting range for config $config");
            }
        }
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

function getWorkItem(array $branchCommits, StdDevManager $stddevs): ?WorkItem {
    foreach ($branchCommits as $branch => $commits) {
        // First process all non-main branches.
        if ($branch == 'origin/main') {
            continue;
        }

        // Build the newest missing commit.
        $workItem = getNewestWorkItem($commits);
        if ($workItem) {
            return $workItem;
        }
    }

    // Then build the main branch.
    $commits = $branchCommits['origin/main'];

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
    // Build the newest missing commit.
    if ($workItem = getNewestWorkItem($commits)) {
        return $workItem;
    }
    return null;
}
