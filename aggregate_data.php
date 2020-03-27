<?php

require __DIR__ . '/src/data_aggregation.php';

if ($argc < 2) {
    throw new Exception("Expected name");
}

$name = $argv[1];

$inDir = __DIR__ . '/llvm-test-suite-build/CTMark';
$outDir = __DIR__ . '/data/experiments/' . $name;
@mkdir($outDir, 0755, true);

$statsFile = $outDir . '/stats.msgpack';
$summaryFile = $outDir . '/summary.json';

$rawData = readRawData($inDir);
$aggData = array_map('aggregateData', $rawData);

file_put_contents($statsFile, msgpack_pack($rawData));
file_put_contents($summaryFile, json_encode($aggData, JSON_PRETTY_PRINT));
