<?php

require __DIR__ . '/src/common.php';
require __DIR__ . '/src/data_aggregation.php';

if ($argc < 3) {
    throw new Exception("Usage: aggregate_data.php hash config");
}

$hash = $argv[1];
$config = $argv[2];
$inDir = __DIR__ . '/llvm-test-suite-build/CTMark';

$outDir = getDirForHash($hash);
@mkdir($outDir, 0755, true);

$rawData = readRawData($inDir);
$aggData = array_map('aggregateData', $rawData);

$summary = getSummaryForHash($hash);
$summary[$config] = $aggData;
writeSummaryForHash($hash, $summary);

$stats = getStatsForHash($hash);
$stats[$config] = $rawData;
writeStatsForHash($hash, $stats);
