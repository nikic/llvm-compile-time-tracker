<?php

require __DIR__ . '/../src/web_common.php';
require __DIR__ . '/../src/data_aggregation.php';

$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$details = isset($_GET['details']);
$stat = $_GET['stat'] ?? DEFAULT_METRIC;
$linkStats = isset($_GET['linkStats']);

if ($from === '' && $to !== '') {
    // If the start commit is missing, try to find the parent of the end commit.
    $lastCommit = null;
    foreach (getMainCommits() as $commit) {
        if ($commit['hash'] === $to) {
            $url = makeUrl("http://{$_SERVER['HTTP_HOST']}/compare.php", [
                'from' => $lastCommit['hash'],
                'to' => $to,
                'stat' => $stat,
            ]);
            header("Location: " . $url);
            die;
        }
        $lastCommit = $commit;
    }
}

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

$swappedParams = ["stat" => $stat, "from" => $to, "to" => $from];

echo "<hr />\n";
echo "Comparing " . formatHash($from) . " to " . formatHash($to)
   . " (<a href=\"" . h(getGitHubCompareUrl($from, $to)) . "\">commits in range</a>)."
   . " <a href=\"" . h(makeUrl("compare.php", $swappedParams)) . "\">Swap commits</a>.\n";

if ($stat === 'task-clock' || $stat === 'wall-time') {
    echo "<div class=\"warning\">Warning: The " . h($stat) . " metric is very noisy and not meaningful for comparisons between specific revisions.</div>";
}

$stddevs = new StdDevManager();
$fromSummary = getSummaryForHash($from);
$fromStats = getStatsForHash($from);
$toSummary = getSummaryForHash($to);
$toStats = getStatsForHash($to);

if (!$fromSummary) {
    reportMissingData($from);
    return;
}
if (!$toSummary) {
    reportMissingData($to);
    return;
}

if (hasBuildError($from)) {
    reportError($from);
}
if (hasBuildError($to)) {
    reportError($to);
}

if ($fromSummary->configNum != $toSummary->configNum) {
    echo "<div class=\"warning\">The server configuration changed between the selected commits. Differences may be spurious.</div>\n";
}

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
        $fromAggMetric = $fromSummaryData[$bench][$stat] ?? null;
        $toAggMetric = $toSummaryData[$bench][$stat] ?? null;
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
                $fromMetric = $fromFile[$stat] ?? null;
                $toMetric = $toFile[$stat] ?? null;
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

if ($linkStats) {
    foreach (['stage1-ReleaseThinLTO', 'stage1-ReleaseLTO-g'] as $config) {
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
}

$fromStage2 = $fromSummary->stage2Stats;
$toStage2 = $toSummary->stage2Stats;
if (!empty($fromStage2) || !empty($toStage2)) {
    echo "<h4>clang build:</h4>\n";
    echo "<table>\n";
    echo "<tr>\n";
    echo "<th>Metric</th>\n";
    echo "<th>Old</th>\n";
    echo "<th>New</th>\n";
    echo "</tr>\n";
    $detailedStats = ['instructions:u', 'wall-time', 'size-file'];
    $stats = array_unique([$stat, ...$detailedStats]);
    foreach ($stats as $stat) {
        echo "<tr>\n";
        echo "<td style=\"text-align: left\">";
        if (in_array($stat, $detailedStats)) {
            $compareClangUrl = makeUrl("compare_clang.php", [
                "from" => $from,
                "to" => $to,
                "stat" => $stat,
            ]);
            echo "<a href=\"$compareClangUrl\">$stat</a>";
        } else {
            echo $stat;
        }
        echo "</td>\n";
        $fromMetric = $fromStage2[$stat] ?? null;
        $toMetric = $toStage2[$stat] ?? null;
        $stddev = $fromSummary->configNum === $toSummary->configNum
            ? $stddevs->getBenchStdDev($fromSummary->configNum, 'build', 'stage2-clang', $stat)
            : null;
        echo "<td>", formatMetric($fromMetric, $stat), "</td>\n";
        echo "<td>", formatMetricDiff($toMetric, $fromMetric, $stat, $stddev), "</td>\n";
        echo "</tr>\n";
    }
    $fromStage1 = $fromSummary->stage1Stats;
    $toStage1 = $toSummary->stage1Stats;
    if (!empty($fromStage1) || !empty($fromStage2)) {
        $stat = 'size-file';
        echo "<tr>\n";
        echo "<td style=\"text-align: left\">$stat (stage1)</td>\n";
        $fromMetric = $fromStage1[$stat] ?? null;
        $toMetric = $toStage1[$stat] ?? null;
        $stddev = $fromSummary->configNum === $toSummary->configNum
            ? $stddevs->getBenchStdDev($fromSummary->configNum, 'build', 'stage1-clang', $stat)
            : null;
        echo "<td>", formatMetric($fromMetric, $stat), "</td>\n";
        echo "<td>", formatMetricDiff($toMetric, $fromMetric, $stat, $stddev), "</td>\n";
        echo "</tr>\n";
    }
    echo "<tr>\n";
    echo "<td style=\"text-align: left\">Ninja trace</td>\n";
    echo "<td>\n";
    if ($fromStage2) {
        $ninjaTraceUrlFrom = makeUrl("ninja_trace.php", ["commit" => $from]);
        echo "<a href=\"$ninjaTraceUrlFrom\">Download</a>\n";
        echo "<a href=\"#\" onclick=\"fetchAndOpen('$ninjaTraceUrlFrom', '$from')\">View</a>\n";
    }
    echo "</td>\n";
    echo "<td>\n";
    if ($toStage2) {
        $ninjaTraceUrlTo = makeUrl("ninja_trace.php", ["commit" => $to]);
        echo "<a href=\"$ninjaTraceUrlTo\">Download</a>\n";
        echo "<a href=\"#\" onclick=\"fetchAndOpen('$ninjaTraceUrlTo', '$to')\">View</a>\n";
    }
    echo "</td>\n";
    echo "</tr>\n";
    echo "</table>\n";
}

printFooter();

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

function reportMissingData(string $hash): void {
    if (hasBuildError($hash)) {
        reportError($hash);
    } else {
        echo "<div class=\"warning\">No data for commit " . formatHash($hash) . ".</div>\n";
    }
}

?>
<script type="text/javascript">
const ORIGIN = 'https://ui.perfetto.dev';

async function fetchAndOpen(traceUrl, hash) {
  const resp = await fetch(traceUrl);
  const blob = await resp.blob();
  const arrayBuffer = await blob.arrayBuffer();
  openTrace(arrayBuffer, hash);
}

function openTrace(arrayBuffer, hash) {
  const win = window.open(ORIGIN);
  if (!win) {
    return;
  }

  const timer = setInterval(() => win.postMessage('PING', ORIGIN), 50);

  const onMessageHandler = (evt) => {
    if (evt.data !== 'PONG') return;

    // We got a PONG, the UI is ready.
    window.clearInterval(timer);
    window.removeEventListener('message', onMessageHandler);

    win.postMessage({
      perfetto: {
        buffer: arrayBuffer,
        title: 'Clang stage2 build at ' + hash,
    }}, ORIGIN);
  };

  window.addEventListener('message', onMessageHandler);
}
</script>
