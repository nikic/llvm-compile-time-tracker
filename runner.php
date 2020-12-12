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

// Time to sleep if there were no new commits
$sleepInterval = 5 * 60;
$commitsFile = __DIR__ . '/data/commits.json';
$ctmarkDir = __DIR__ . '/llvm-test-suite-build/CTMark';
$configNum = 1;
$runs = 2;
$timeout = 5 * 60; // 5 minutes

$firstCommit = '8f5b44aead89a56c6fbf85ccfda03ae1e82ac431';
$branchPatterns = [
    '~^[^/]+/perf/.*~',
    '~^origin/main$~',
    '~^origin/release/11.x$~',
];

$gitWrapper = new GitWrapper();
$repo = $gitWrapper->workingCopy(__DIR__ . '/llvm-project');
$dataRepo = $gitWrapper->workingCopy(__DIR__ . '/data');
$stddevs = new StdDevManager();

while (true) {
    logInfo("Fetching branches");
    try {
        $repo->fetch('--all');
    } catch (GitException $e) {
        // Log the failure, but carry on, we have plenty of old commits to build!
        logError($e->getMessage());
    }

    // Redoing all this work might get inefficient at some point...
    logInfo("Fetching commits");
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

    $stats = [];
    $summary = new Summary($configNum, $sizeStats, []);
    foreach ($configs as $config) {
        $rawDatas = [];
        for ($run = 1; $run <= $runs; $run++) {
            logInfo("Building $config configuration (run $run)");
            try {
                try {
                    runCommand("./build_llvm_test_suite.sh $config", $timeout);
                } catch (ProcessTimedOutException $e) {
                    // Make sure we kill hanging clang processes.
                    try {
                        runCommand("killall clang*");
                    } catch (CommandException $e) {
                        /* We don't care if there was nothing to kill. */
                    }
                    $process = $e->getProcess();
                    throw new CommandException(
                        $e->getMessage(), $process->getOutput(), $process->getErrorOutput()
                    );
                }
            } catch (CommandException $e) {
                echo $e->getMessage(), "\n";
                file_put_contents($hashDir . '/error', $e->getDebugOutput());
                break;
            }
            $rawDatas[] = readRawData($ctmarkDir);
        }

        $data = $runs > 1 ? averageRawData($rawDatas) : $rawDatas[0];
        $stats[$config] = $data;
        $summary->data[$config] = summarizeData($data);
    }

    writeSummaryForHash($hash, $summary);
    writeStatsForHash($hash, $stats);
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
    if ($branch === 'origin/main') {
        return getParsedLog($repo, $branch, $firstCommit);
    }
    $mergeBase = trim($repo->run('merge-base', [$branch, 'origin/main']));
    return getParsedLog($repo, $branch, $mergeBase);
}

function haveData(string $hash): bool {
    return file_exists(getDirForHash($hash) . '/summary.json');
}

function haveError(string $hash): bool {
    return file_exists(getDirForHash($hash) . '/error');
}

function getMissingConfigs(string $hash): array {
    if (haveError($hash) || haveData($hash)) {
        return [];
    }

    return CONFIGS;
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
            $stddev = $stddevs->getBenchStdDev(/* TODO */ 1, $config, $bench, $stat);
            if ($stddev !== null && $diff >= $sigma * $stddev) {
                return true;
            }
        }
    }
    return false;
}

function getInterestingWorkItem(array $missingRanges, StdDevManager $stddevs): ?WorkItem {
    foreach ($missingRanges as $missingRange) {
        foreach (CONFIGS as $config) {
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
