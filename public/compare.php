<?php

require __DIR__ . '/../src/web_common.php';
require __DIR__ . '/../src/data_aggregation.php';

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$details = isset($_GET['details']);
$config = $_GET['config'] ?? 'O3';
$stat = $_GET['stat'] ?? 'instructions';

if (!is_string($from) || !is_string($to)) {
    die("Missing from/to");
}

$fromStats = getStats($from, $config);
if (!$fromStats) {
    die("No data for " . h($fromStats) . " in config " . h($config));
}

$toStats = getStats($to, $config);
if (!$toStats) {
    die("No data for " . h($toStats) . " in config ". h($config));
}

printStyle();

echo "<table>\n";
foreach ($fromStats as $bench => $fromFiles) {
    $toFiles = $toStats[$bench];
    $fromAggMetric = aggregateData($fromFiles)[$stat];
    $toAggMetric = aggregateData($toFiles)[$stat];
    echo "<tr>\n";
    echo "<td>$bench</td>\n";
    echo "<td>", formatMetric($fromAggMetric, $stat), "</td>\n";
    echo "<td>", formatMetricDiff($toAggMetric, $fromAggMetric, $stat), "</td>\n";
    echo "</tr>\n";
    if ($details) {
        foreach ($fromFiles as $i => $fromFile) {
            $toFile = $toFiles[$i];
            $file = $fromFile['file'];
            if ($file != $toFile['file']) {
                throw new Exception('Mismatch');
            }
            $fromMetric = $fromFile[$stat];
            $toMetric = $toFile[$stat];
            echo "<tr>\n";
            echo "<td>&nbsp;&nbsp;&nbsp;&nbsp;$file</td>\n";
            echo "<td>", formatMetric($fromMetric, $stat), "</td>\n";
            echo "<td>", formatMetricDiff($toMetric, $fromMetric, $stat), "</td>\n";
            echo "</tr>\n";
        }
    }
}
echo "</table>\n";
