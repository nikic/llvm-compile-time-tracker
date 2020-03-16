<?php

require __DIR__ . '/../src/web_common.php';
require __DIR__ . '/../src/data_aggregation.php';

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$details = isset($_GET['details']);
$stat = $_GET['stat'] ?? 'instructions';

printStyle();

echo "<form>\n";
echo "<label>From: <input name=\"from\" value=\"" . h($from ?? '') . "\" /></label>\n";
echo "<label>To: <input name=\"to\" value=\"" . h($to ?? '') . "\" /></label>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
echo "<input type=\"submit\" value=\"Compare\" />\n";
echo "</form>\n";
if (!is_string($from) || !is_string($to)) {
    return;
}

$stddevs = getStddevData();
if (!$details) {
    foreach (CONFIGS as $config) {
        $fromStats = getSummary($from, $config);
        $toStats = getSummary($to, $config);
        if (!$fromStats || !$toStats) {
            continue;
        }
        $fromStats = array_column_with_keys($fromStats, $stat);
        $toStats = array_column_with_keys($toStats, $stat);

        echo "<h4>$config:</h4>\n";
        echo "<table>\n";
        echo "<tr>\n";
        echo "<th>Benchmark</th>";
        echo "<th>Old</th>";
        echo "<th>New</th>";
        echo "<tr>\n";
        echo "</tr>\n";
        foreach ($fromStats as $bench => $fromMetric) {
            $toMetric = $toStats[$bench];
            $stddev = getStddev($stddevs, $config, $bench, $stat);
            echo "<tr>\n";
            echo "<td style=\"text-align: left\">$bench</td>\n";
            echo "<td>", formatMetric($fromMetric, $stat), "</td>\n";
            echo "<td>", formatMetricDiff($toMetric, $fromMetric, $stat, $stddev), "</td>\n";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
} else {
    // TODO: Doesn't seem to be terrible useful, possibly remove this?
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
            $stddev = getStddev($stddevs, $config, $bench, $stat);
            echo "<tr>\n";
            echo "<td style=\"text-align: left\">$bench</td>\n";
            echo "<td>", formatMetric($fromAggMetric, $stat), "</td>\n";
            echo "<td>", formatMetricDiff($toAggMetric, $fromAggMetric, $stat, $stddev), "</td>\n";
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
                    echo "<td style=\"text-align: left\">&nbsp;&nbsp;&nbsp;&nbsp;$file</td>\n";
                    echo "<td>", formatMetric($fromMetric, $stat), "</td>\n";
                    echo "<td>", formatMetricDiff($toMetric, $fromMetric, $stat, $stddev), "</td>\n";
                    echo "</tr>\n";
                }
            }
        }
        echo "</table>\n";
    }
}
