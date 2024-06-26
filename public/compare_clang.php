<?php

require __DIR__ . '/../src/web_common.php';
require __DIR__ . '/../src/build_log.php';

$sortOptions = [
    'alphabetic',
    'absolute',
    'absolute-difference',
    'relative-difference',
    'interestingness',
];

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$stat = $_GET['stat'] ?? DEFAULT_METRIC;
$displayStat = $stat == 'wall-time' ? 'task-clock' : $stat;
$sortBy = $_GET['sortBy'] ?? 'absolute';

printHeader();
echo "<form>\n";
echo "<label>From: <input name=\"from\" value=\"" . h($from ?? '') . "\" /></label>\n";
echo "<label>To: <input name=\"to\" value=\"" . h($to ?? '') . "\" /></label>\n";
echo "<label>Metric: "; printStatSelect($stat, BUILD_LOG_METRICS); echo "</label>\n";
echo "<label>Sort by: "; printSelect("sortBy", $sortBy, $sortOptions); echo "</label>\n";
echo "<input type=\"submit\" value=\"Compare\" />\n";
echo "</form>\n";
echo "<hr />\n";
echo "Comparing " . formatHash($from) . " to " . formatHash($to)
   . " (<a href=\"" . h(getGitHubCompareUrl($from, $to)) . "\">commits in range</a>).\n";
echo "<h4>stage2-clang:</h4>\n";

if (!is_string($from) || !is_string($to)) {
    return;
}

if (hasBuildError($from)) {
    reportError($from);
}

if (hasBuildError($to)) {
    reportError($to);
}

$fromData = readBuildLog($from);
if ($fromData === null) {
    echo "<div class=\"warning\">No data for commit " . formatHash($from) . ".</div>\n";
    return;
}

$toData = readBuildLog($to);
if ($toData === null) {
    echo "<div class=\"warning\">No data for commit " . formatHash($to) . ".</div>\n";
    return;
}

$stddevs = new StdDevManager();
$configNum = 4;

$files = array_unique(array_merge(array_keys($fromData), array_keys($toData)));
sort($files);
if ($sortBy !== 'alphabetic') {
    usort($files, function(string $f1, string $f2) use ($fromData, $toData, $sortBy, $stat, $stddevs, $configNum) {
        $from1 = ($fromData[$f1] ?? null)?->getStat($stat);
        $to1 = ($toData[$f1] ?? null)?->getStat($stat);
        $from2 = ($fromData[$f2] ?? null)?->getStat($stat);
        $to2 = ($toData[$f2] ?? null)?->getStat($stat);

        $noData1 = $to1 === null && $from1 === null;
        $noData2 = $to2 === null && $from2 === null;
        if ($noData1 !== $noData2) {
            // Sort files without data to the end.
            return $noData1 <=> $noData2;
        }
        if ($noData1 && $noData2) {
            // Keep files without data in alphabetic order.
            return $f1 <=> $f2;
        }

        if ($sortBy === 'absolute') {
            return ($from2 ?? $to2) <=> ($from1 ?? $to1);
        }

        $diff1 = abs($from1 - $to1);
        $diff2 = abs($from2 - $to2);
        if ($sortBy == 'absolute-difference') {
            return $diff2 <=> $diff1;
        }
        if ($sortBy == 'relative-difference') {
            return fdiv($diff2, $from2) <=> fdiv($diff1, $from1);
        }
        $stddev1 = $stddevs->getFileStdDev($configNum, 'stage2-clang', $f1, $stat);
        $stddev2 = $stddevs->getFileStdDev($configNum, 'stage2-clang', $f2, $stat);
        return getInterestingness($diff2, $stddev2) <=> getInterestingness($diff1, $stddev1);
    });
}

echo "<table>\n";
echo "<tr>\n";
echo "<th>File</th>\n";
echo "<th>Old</th>\n";
echo "<th>New</th>\n";
echo "</tr>\n";
foreach ($files as $file) {
    $fromEntry = $fromData[$file] ?? null;
    $fromMetric = $fromEntry?->getStat($stat);
    $toEntry = $toData[$file] ?? null;
    $toMetric = $toEntry?->getStat($stat);
    $stddev = $stddevs->getFileStdDev($configNum, 'stage2-clang', $file, $stat);
    echo "<tr>\n";
    echo "<td style=\"text-align: left\">$file</td>\n";
    echo "<td>", formatMetric($fromMetric, $displayStat), "</td>\n";
    echo "<td>", formatMetricDiff($toMetric, $fromMetric, $displayStat, $stddev), "</td>\n";
    echo "</tr>\n";
}
echo "</table>\n";
printFooter();
