<?php

$commits = $_GET['commits'] ?? [];
$stat = $_GET['stat'];
if (!is_array($commits) || count($commits) != 2) {
    die('Exactly two commits must be selected.');
}

$commit1 = $commits[0] ?? null;
$commit2 = $commits[1] ?? null;
if (!is_string($commit1) || !is_string($commit2) || !is_string($stat)) {
    die('Invalid parameters.');
}

header("Location: http://{$_SERVER['HTTP_HOST']}/compare.php"
    . "?from=" . urlencode($commit2)
    . "&to=" . urlencode($commit1)
    . "&stat=" . urlencode($stat));
