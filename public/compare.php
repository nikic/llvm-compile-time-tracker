<?php

require __DIR__ . '/../src/web_common.php';
require __DIR__ . '/../src/data_aggregation.php';

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$details = isset($_GET['details']);
$stat = $_GET['stat'] ?? 'instructions';

printHeader();

echo "<form>\n";
echo "<label>From: <input name=\"from\" value=\"" . h($from ?? '') . "\" /></label>\n";
echo "<label>To: <input name=\"to\" value=\"" . h($to ?? '') . "\" /></label>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
echo "<label>Per-file details: <input type=\"checkbox\" name=\"details\""
   . ($details ? " checked" : "") . " /></label>";
echo "<input type=\"submit\" value=\"Compare\" />\n";
echo "</form>\n";
if (!is_string($from) || !is_string($to)) {
    return;
}

echo "<hr />\n";
echo "Comparing " . formatHash($from) . " to " . formatHash($to)
   . " (<a href=\"" . h(getGitHubCompareUrl($from, $to)) . "\">commits in range</a>).\n";

if ($stat === 'task-clock' || $stat === 'wall-time') {
    echo "<div class=\"warning\">Warning: The " . h($stat) . " metric is very noisy and not meaningful for comparisons between specific revisions.</div>";
}

$stddevs = getStddevData();
$fileStddevs = getPerFileStddevData();
foreach (CONFIGS as $config) {
    $fromStats = getStats($from, $config);
    $toStats = getStats($to, $config);
    if (!$fromStats || !$toStats) {
        continue;
    }

    $fromSummary = addGeomean(array_map('aggregateData', $fromStats));
    $toSummary = addGeomean(array_map('aggregateData', $toStats));
    $benches = array_keys($fromSummary);

    echo "<h4>$config:</h4>\n";
    echo "<table>\n";
    echo "<tr>\n";
    echo "<th>Benchmark</th>";
    echo "<th>Old</th>";
    echo "<th>New</th>";
    echo "</tr>\n";
    foreach ($benches as $bench) {
        $fromFiles = $fromStats[$bench] ?? [];
        $toFiles = $toStats[$bench] ?? [];
        $fromAggMetric = $fromSummary[$bench][$stat];
        $toAggMetric = $toSummary[$bench][$stat];
        $stddev = getStddev($stddevs, $config, $bench, $stat);
        echo "<tr>\n";
        echo "<td style=\"text-align: left\">$bench</td>\n";
        echo "<td>", formatMetric($fromAggMetric, $stat), "</td>\n";
        echo "<td>", formatMetricDiff($toAggMetric, $fromAggMetric, $stat, $stddev), "</td>\n";
        echo "</tr>\n";
        if ($details) {
            foreach ($fromFiles as $file => $fromFile) {
                $toFile = $toFiles[$file];
                $fromMetric = $fromFile[$stat];
                $toMetric = $toFile[$stat];
                $stddev = getStddev($fileStddevs, $config, $file, $stat);
                echo "<tr>\n";
                echo "<td style=\"text-align: left\">    $file</td>\n";
                echo "<td>", formatMetric($fromMetric, $stat), "</td>\n";
                echo "<td>", formatMetricDiff($toMetric, $fromMetric, $stat, $stddev), "</td>\n";
                echo "</tr>\n";
            }
        }
    }
    echo "</table>\n";
}

foreach (['ReleaseThinLTO', 'ReleaseLTO-g'] as $config) {
    $fromStats = getStats($from, $config);
    $toStats = getStats($to, $config);
    if (!$fromStats || !$toStats) {
        continue;
    }

    $fromSummary = addGeomean(array_map('getLinkStats', $fromStats));
    $toSummary = addGeomean(array_map('getLinkStats', $toStats));
    $benches = array_keys($fromSummary);

    echo "<h4>$config (link only):</h4>\n";
    echo "<table>\n";
    echo "<tr>\n";
    echo "<th>Benchmark</th>";
    echo "<th>Old</th>";
    echo "<th>New</th>";
    echo "</tr>\n";
    foreach ($benches as $bench) {
        $fromAggMetric = $fromSummary[$bench][$stat];
        $toAggMetric = $toSummary[$bench][$stat];
        $stddev = getStddev($fileStddevs, $config, $fromSummary[$bench]['file'], $stat);
        echo "<tr>\n";
        echo "<td style=\"text-align: left\">$bench</td>\n";
        echo "<td>", formatMetric($fromAggMetric, $stat), "</td>\n";
        echo "<td>", formatMetricDiff($toAggMetric, $fromAggMetric, $stat, $stddev), "</td>\n";
        echo "</tr>\n";
    }
    echo "</table>\n";
}

printFooter();

function getGitHubCompareUrl(string $fromHash, string $toHash): string {
    return "https://github.com/llvm/llvm-project/compare/"
         . urlencode($fromHash) . "..." . urlencode($toHash);
}

function getLinkStats(array $statsList): array {
    foreach ($statsList as $stats) {
        if (strpos($stats['file'], '.link') === false) {
            continue;
        }

        return $stats;
    }

    throw new Exception('No link stats found');
}
