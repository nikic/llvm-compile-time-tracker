<?php

require __DIR__ . '/src/common.php';

$outFile = __DIR__ . '/first_commits.json';

$curConfigNum = -1;
$firstCommits = [];
foreach (getMainCommits() as $commit) {
    $summary = getSummaryForHash($commit['hash']);
    if ($summary === null) {
        continue;
    }

    if ($summary->configNum > $curConfigNum) {
        // This intentionally takes the last time we switch to the new config, not the first.
        // This is the point where the config was enabled. Previous ones are due to base commits
        // of user branches, which is exactly what we want to ignore.
        $firstCommits[$summary->configNum] = $commit['hash'];
    }
    $curConfigNum = $summary->configNum;
}

file_put_contents($outFile, json_encode($firstCommits, JSON_PRETTY_PRINT));
