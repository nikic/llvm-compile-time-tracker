<?php

use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use Symfony\Component\Process\Process;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/common.php';

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
    //$repo->fetch('--all');

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

    list($hash, $configs) = $workItem;
    logInfo("Building $hash");

    $repo->checkout($hash);
    runCommand('./build_llvm_project.sh');

    foreach ($configs as $config) {
        runCommand("./build_llvm_test_suite.sh $config");

        // TODO: Don't call into PHP here.
        $outDir = $hash . '/' . $config;
        runCommand("php aggregate_data.php $outDir");
    }

    file_put_contents($commitsFile, json_encode($branchCommits, JSON_PRETTY_PRINT));
    $dataRepo->add('.');
    $dataRepo->commit('-m', 'Add data');
    $dataRepo->push('origin', 'master');
}

function logInfo(string $str) {
    echo "[", date('Y-m-d H:i:s.v') , "] ", $str, "\n";
}

function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(null);
    $exitCode = $process->run(function($type, $buffer) {
        echo $buffer;
    });
    if ($exitCode !== 0) {
        throw new Exception("Execution of \"$command\" failed");
    }
}

function getParsedLog(GitWorkingCopy $repo, string $branch, string $baseCommit) {
    $log = $repo->log('--pretty=format:%H;%an;%ae;%cI;%s', '--reverse', "$baseCommit^..$branch");
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
    $experimentsDir = __DIR__ . '/data/experiments';
    return is_dir($experimentsDir . '/' . $hash . '/' . $config);
}

function getMissingConfigs(string $hash): array {
    $configs = [];
    foreach (CONFIGS as $wantedConfig) {
        if (!haveData($hash, $wantedConfig)) {
            $configs[] = $wantedConfig;
        }
    }
    return $configs;
}

function getHeadWorkItem(array $commits): ?array {
    $head = $commits[count($commits) - 1];
    if ($configs = getMissingConfigs($hash)) {
        return [$hash, $configs];
    }
    return null;
}

function getNewestWorkItem(array $commits): ?array {
    // Process newer commits first.
    foreach (array_reverse($commits) as $commit) {
        $hash = $commit['hash'];
        if ($configs = getMissingConfigs($hash)) {
            return [$hash, $configs];
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

function getBisectWorkItem(array $missingHashes): array {
    $count = count($missingHashes);
    $idx = intdiv($count, 2);
    $hash = $missingHashes[$idx];
    return [$hash, getMissingConfigs($hash)];
}

function isInteresting(array $summary1, array $summary2, string $config, array $stddevs): bool {
    $stat = 'instructions';
    $sigma = 4;
    foreach ($summary1 as $bench => $stats1) {
        $stats2 = $summary2[$bench];
        $value1 = $stats1[$stat];
        $value2 = $stats2[$stat];
        $diff = abs($value1 - $value2);
        $stddev = getStddev($stddevs, $config, $bench, $stat);
        if ($diff >= $sigma * $stddev) {
            return true;
        }
    }
    return false;
}

function getInterestingWorkItem(array $missingRanges, array $stddevs) {
    foreach ($missingRanges as list($hash1, $hash2, $missingHashes)) {
        foreach (CONFIGS as $config) {
            $summary1 = getSummary($hash1, $config);
            $summary2 = getSummary($hash2, $config);
            if (!$summary1 || !$summary2) {
                continue;
            }

            if (isInteresting($summary1, $summary2, $config, $stddevs)) {
                return getBisectWorkItem($missingHashes);
            }
        }
    }
    return null;
}

function getWorkItem(array $branchCommits, array $stddevs): ?array {
    foreach ($branchCommits as $branch => $commits) {
        // If there's a new commit, always build it first.
        /*if ($workItem = getHeadWorkItem($commits)) {
            return $workItem;
        }*/

        if ($branch == 'origin/master') {
            $missingRanges = getMissingRanges($commits);
            if ($workItem = getInterestingWorkItem($missingRanges, $stddevs)) {
                return $workItem;
            }
        }

        // For non-master branches, build the newest missing commit.
        $workItem = getNewestWorkItem($commits);
        if ($workItem) {
            return $workItem;
        }
    }
    return null;
}
