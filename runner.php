<?php

use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/vendor/autoload.php';

// Time to sleep if there were no new commits
$sleepInterval = 5 * 60;
$commitsFile = __DIR__ . '/data/commits.json';

$firstCommit = '92f7e8133ae98e1f300bad164c4099b2e609bae7';
$branchPatterns = [
    '~^nikic/perf/.*~',
    '~^origin/master$~',
];

$gitWrapper = new GitWrapper();
$repo = $gitWrapper->workingCopy(__DIR__ . '/llvm-project');

while (true) {
    $repo->fetch('--all');

    // Redoing all this work might get inefficient at some point...
    $branches = getRelevantBranches($repo, $branchPatterns);
    $branchCommits = [];
    foreach ($branches as $branch) {
        $branchCommits[$branch] = getBranchCommits($repo, $branch, $firstCommit);
    }

    $hash = getWorkItem($branchCommits);
    if ($hash === null) {
        // Wait before checking for a new commit.
        sleep($sleepInterval);
        continue;
    }

    file_put_contents($commitsFile, json_encode($branchCommits, JSON_PRETTY_PRINT));

    $repo->checkout($hash);
    runCommand('./build_llvm_project.sh');
    runCommand('./build_llvm_test_suite.sh');

    // TODO: Don't call into PHP here.
    $outDir = $hash . '/O3';
    runCommand("php aggregate_data.php $outDir");
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

function haveData(string $hash): bool {
    $experimentsDir = __DIR__ . '/data/experiments';
    return is_dir($experimentsDir . '/' . $hash);
}

function getWorkItem(array $branchCommits): ?string {
    foreach ($branchCommits as $commits) {
        foreach ($commits as $commit) {
            $hash = $commit['hash'];
            if (!haveData($hash)) {
                return $hash;
            }
        }
    }
    return null;
}
