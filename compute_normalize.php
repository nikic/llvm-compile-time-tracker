<?php

require __DIR__ . '/src/common.php';
require __DIR__ . '/src/build_log.php';

$outDir = __DIR__ . '/normalize';

$curConfigNum = 0;
$lastCommit = null;
$lastSummary = null;
foreach (getMainCommits() as $commit) {
    $summary = getSummaryForHash($commit['hash']);
    if ($summary === null) {
        continue;
    }

    if ($curConfigNum > $summary->configNum) {
        // Degenerate case: Older config after newer.
        continue;
    }

    if ($curConfigNum == $summary->configNum) {
        $lastCommit = $commit;
        $lastSummary = $summary;
        continue;
    }

    $curConfigNum = $summary->configNum;
    var_dump($summary->configNum);

    $normalizeData = [];
    $addStat = function($config, $bench, $stat, $lastValue, $newValue) use(&$normalizeData) {
        if ($newValue === null) {
            return;
        }

        $normalizeData[$config][$bench][$stat] = round($lastValue / $newValue, 4);
    };

    // TODO: Should probably use more than one data point for normalization...
    foreach ($lastSummary->data as $config => $configData) {
        foreach ($configData as $bench => $benchData) {
            foreach ($benchData as $stat => $lastValue) {
                $newValue = $summary->data[$config][$bench][$stat] ?? null;
                $addStat($config, $bench, $stat, $lastValue, $newValue);
            }
        }
    }

    foreach ($lastSummary->stage1Stats as $stat => $lastValue) {
        $newValue = $summary->stage1Stats[$stat] ?? null;
        $addStat('build', 'stage1-clang', $stat, $lastValue, $newValue);
    }

    foreach ($lastSummary->stage2Stats as $stat => $lastValue) {
        $newValue = $summary->stage2Stats[$stat] ?? null;
        $addStat('build', 'stage2-clang', $stat, $lastValue, $newValue);
    }

    file_put_contents(
        $outDir . '/summary_' . $curConfigNum . '.json',
        json_encode($normalizeData, JSON_PRETTY_PRINT));
}
