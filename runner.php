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
    '~^nikic/perf/.*~',
    '~^origin/master$~',
];

$gitWrapper = new GitWrapper();
$repo = $gitWrapper->workingCopy(__DIR__ . '/llvm-project');
$dataRepo = $gitWrapper->workingCopy(__DIR__ . '/data');

while (true) {
    $repo->fetch('--all');

    // Redoing all this work might get inefficient at some point...
    $branches = getRelevantBranches($repo, $branchPatterns);
    $branchCommits = [];
    foreach ($branches as $branch) {
        $branchCommits[$branch] = getBranchCommits($repo, $branch, $firstCommit);
    }

    $workItem = getWorkItem($branchCommits);
    if ($workItem === null) {
        // Wait before checking for a new commit.
        sleep($sleepInterval);
        continue;
    }

    list($hash, $configs) = $workItem;

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

function getWorkItem(array $branchCommits): ?array {
    foreach ($branchCommits as $commits) {
        // Process newer commits first.
        foreach (array_reverse($commits) as $commit) {
            $hash = $commit['hash'];
            $configs = [];
            foreach (CONFIGS as $wantedConfig) {
                if (!haveData($hash, $wantedConfig)) {
                    $configs[] = $wantedConfig;
                }
            }
            if ($configs) {
                return [$hash, $configs];
            }
        }
    }
    return null;
}
