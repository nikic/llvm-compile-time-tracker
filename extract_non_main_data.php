<?php

if ($argc < 3) {
    echo "Usage: php extract_non_master_data.php old-data/ new-data/\n";
    exit(1);
}

$inputDir = $argv[1];
$resultDir = $argv[2];
if (!is_dir($inputDir)) {
	echo "Input directory $inputDir does not exist.\n";
	exit(1);
}
if (!is_dir($resultDir)) {
	echo "Input directory $resultDir does not exist.\n";
	exit(1);
}

$commitsFile = $inputDir . '/commits.json';
$commits = json_decode(file_get_contents($commitsFile), true);

$extractedCommitsFile = $resultDir . '/commits.json';
$extractedCommits = [];

foreach ($commits as $branch => $branchCommits) {
    if ($branch === 'origin/main') {
        continue;
    }

    // Move branches to new commits file.
    $extractedCommits[$branch] = $branchCommits;
    unset($commits[$branch]);

    // Move the data files to the new location.
    foreach ($branchCommits as $commit) {
        $hash = $commit['hash'];
        $from = $inputDir . '/experiments/' . substr($hash, 0, 2) . '/' . substr($hash, 2);
        if (!file_exists($from)) {
            continue;
        }

        $to = $resultDir . '/experiments/' . substr($hash, 0, 2) . '/' . substr($hash, 2);
        @mkdir(dirname($to), 0777, true);
        rename($from, $to);
    }
}

file_put_contents($commitsFile, json_encode($commits, JSON_PRETTY_PRINT));
file_put_contents($extractedCommitsFile, json_encode($extractedCommits, JSON_PRETTY_PRINT));
