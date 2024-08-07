<?php

require __DIR__ . '/../src/web_common.php';

$commit = getStringParam('commit');

printHeader();

if ($commit === null) {
    echo "No commit specified.";
    return;
}

if (!isCommitHash($commit)) {
    echo "Commit hash is malformed.";
    return;
}

$errorFile = getPathForHash($commit, '/error');
if ($errorFile === null) {
    echo "No error for commit " . formatHash($commit) . ".";
    return;
}

echo "Error output for commit " . formatHash($commit) . ":<br />";
echo "<pre>" . htmlspecialchars(file_get_contents($errorFile)) . "</pre>";
