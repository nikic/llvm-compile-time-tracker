<?php

require __DIR__ . '/src/common.php';
$commitsFile = DATA_DIR . '/commits.json';
$stddevFile = __DIR__ . '/stddev.json';
$branchCommits = json_decode(file_get_contents($commitsFile), true);
$masterCommits = $branchCommits['origin/master'];
$from = 'bb8622094d77417c629e45fc9964d0b699019f22';
//$to = 'c93652517c810a3afafe6d2a57b528bf2692a165';
$to = '564180429818dd48f2fab970fdb42d172ebd2a5f';

$commits = [];
$foundFirst = false;
foreach ($masterCommits as $commit) {
    $hash = $commit['hash'];
    if (!$foundFirst) {
        if ($hash !== $from) {
            continue;
        }
        $foundFirst = true;
    }
    $commits[] = $hash;
    if ($hash === $to) {
        break;
    }
}

$data = [];
foreach ($commits as $hash) {
    foreach (CONFIGS as $config) {
        $summary = getSummary($hash, $config);
        if (!$summary) {
            continue;
        }

        foreach ($summary as $bench => $stats) {
            foreach ($stats as $stat => $value) {
                $data[$config][$bench][$stat][] = $value;
            }
        }
    }
}

$stddevs = [];
foreach ($data as $config => $configData) {
    foreach ($configData as $bench => $benchData) {
        foreach ($benchData as $stat => $statData) {
            $stddevs[$config][$bench][$stat] = stddev($statData);
        }
    }
}

file_put_contents($stddevFile, json_encode($stddevs, JSON_PRETTY_PRINT));

function avg(array $values): float {
    return array_sum($values) / count($values);
}

function stddev(array $values): float {
    $avg = avg($values);
    $sqSum = 0.0;
    foreach ($values as $value) {
        $delta = $value - $avg;
        $sqSum += $delta * $delta;
    }
    return sqrt($sqSum / (count($values) - 1));
}
