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

$stddevs = new StdDevManager();
$fromSummary = getSummaryForHash($from);
$fromStats = getStatsForHash($from);
$toSummary = getSummaryForHash($to);
$toStats = getStatsForHash($to);

foreach (CONFIGS as $config) {
    $fromSummaryData = $fromSummary->getConfig($config);
    $toSummaryData = $toSummary->getConfig($config);
    if (!$fromSummaryData || !$toSummaryData) {
        continue;
    }

    echo "<h4>$config:</h4>\n";
    echo "<table>\n";
    echo "<tr>\n";
    echo "<th>Benchmark</th>";
    echo "<th>Old</th>";
    echo "<th>New</th>";
    echo "</tr>\n";
    foreach (BENCHES_GEOMEAN_LAST as $bench) {
        $fromAggMetric = $fromSummaryData[$bench][$stat];
        $toAggMetric = $toSummaryData[$bench][$stat];
        $stddev = $fromSummary->configNum === $toSummary->configNum
            ? $stddevs->getBenchStdDev($fromSummary->configNum, $config, $bench, $stat)
            : null;
        echo "<tr>\n";
        echo "<td style=\"text-align: left\">$bench</td>\n";
        echo "<td>", formatMetric($fromAggMetric, $stat), "</td>\n";
        echo "<td>", formatMetricDiff($toAggMetric, $fromAggMetric, $stat, $stddev), "</td>\n";
        echo "</tr>\n";
        if ($details) {
            $fromFiles = $fromStats[$config][$bench] ?? [];
            $toFiles = $toStats[$config][$bench] ?? [];
            ksort($fromFiles);
            foreach ($fromFiles as $file => $fromFile) {
                $toFile = $toFiles[$file];
                $fromMetric = $fromFile[$stat];
                $toMetric = $toFile[$stat];
                $stddev = $fromSummary->configNum === $toSummary->configNum
                    ? $stddevs->getFileStdDev($fromSummary->configNum, $config, $file, $stat)
                    : null;
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

    $fromSummaryData = addGeomean(array_map('getLinkStats', $fromStats));
    $toSummaryData = addGeomean(array_map('getLinkStats', $toStats));

    echo "<h4>$config (link only):</h4>\n";
    echo "<table>\n";
    echo "<tr>\n";
    echo "<th>Benchmark</th>";
    echo "<th>Old</th>";
    echo "<th>New</th>";
    echo "</tr>\n";
    foreach (BENCHES_GEOMEAN_LAST as $bench) {
        $fromAggMetric = $fromSummaryData[$bench][$stat];
        $toAggMetric = $toSummaryData[$bench][$stat];
        $stddev = $fromSummary->configNum === $toSummary->configNum
            ? $stddevs->getFileStdDev($fromSummary->configNum, $config, $fromSummaryData[$bench]['file'], $stat)
            : null;
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
    foreach ($statsList as $file => $stats) {
        if (strpos($file, '.link') === false) {
            continue;
        }

        $stats['file'] = $file;
        return $stats;
    }

    throw new Exception('No link stats found');
}
