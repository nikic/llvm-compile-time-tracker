<?php

require __DIR__ . '/../src/web_common.php';
require __DIR__ . '/../src/data_aggregation.php';

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$details = isset($_GET['details']);
$stat = $_GET['stat'] ?? 'instructions';

if (!is_string($from) || !is_string($to)) {
    die("Missing from/to");
}

printStyle();

foreach (CONFIGS as $config) {
    $fromStats = getStats($from, $config);
    $toStats = getStats($to, $config);
    if (!$fromStats || !$toStats) {
        continue;
    }

    echo "<h4>$config:</h4>\n";
    echo "<table>\n";
    echo "<tr>\n";
    echo "<th>Benchmark</th>";
    echo "<th>Old</th>";
    echo "<th>New</th>";
    echo "<tr>\n";
    echo "</tr>\n";
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
}
