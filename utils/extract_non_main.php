<?php

if ($argc < 3) {
    exit("Usage: php extract_non_main.php data-N/commits.json data/commits.json\n");
}

$old_file = $argv[1];
$new_file = $argv[2];

if (!file_exists($old_file)) {
    exit("File $old_file does not exist.");
}

$commits = json_decode(file_get_contents($old_file), true);
$nonMainCommits = [];
foreach ($commits as $branch => $branchCommits) {
    if ($branch === 'origin/main') {
        continue;
    }
    $nonMainCommits[$branch] = $branchCommits;
    unset($commits[$branch]);
}

file_put_contents($new_file, json_encode($nonMainCommits, JSON_PRETTY_PRINT));
file_put_contents($old_file, json_encode($commits, JSON_PRETTY_PRINT));
