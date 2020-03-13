<?php

use GitWrapper\GitWrapper;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/vendor/autoload.php';

// Time to sleep if there were no new commits
$sleepInterval = 5 * 60;

$gitWrapper = new GitWrapper();
$repo = $gitWrapper->workingCopy(__DIR__ . '/llvm-project');
$prevHead = null;

while (true) {
    $repo->fetch('origin');
    $repo->reset('--hard', 'origin/master');
    $head = trim($repo->run('rev-parse', ['HEAD']));
    if ($head === $prevHead) {
        // Wait before checking for a new commit.
        sleep($sleepInterval);
        continue;
    }
    $prevHead = $head;

    runCommand('./build_llvm_project.sh');
    runCommand('./build_llvm_test_suite.sh');

    // TODO: Don't call into PHP here.
    $outDir = $head . '/O3';
    runCommand("php aggregate_data.php $outDir");
}

function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $exitCode = $process->run(function($type, $buffer) {
        echo $buffer;
    });
    if ($exitCode !== 0) {
        throw new Exception("Execution of \"$command\" failed");
    }
}
