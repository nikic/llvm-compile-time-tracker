<?php

require __DIR__ . '/../src/web_common.php';

function getData(
        iterable $commits, array $configs, array $benches, string $stat,
        int $interval, ?DateTime $startDate): array {
    $singleBench = count($benches) === 1 ? $benches[0] : null;
    $hashes = [];
    $dates = [];
    $data = [];
    $i = 0;
    foreach ($commits as $commit) {
        if ($startDate) {
            $commitDate = new DateTime($commit['commit_date']);
            if ($commitDate < $startDate) {
                continue;
            }
        }

        if (++$i < $interval) {
            continue;
        }

        $hash = $commit['hash'];
        $hasAtLeastOneConfig = false;
        $values = [];
        $fullSummary = getSummaryForHash($hash);
        if ($fullSummary === null) {
            continue;
        }

        foreach ($configs as $config) {
            $summary = $fullSummary->data[$config] ?? [];
            foreach ($benches as $bench) {
                if ($bench == 'clang') {
                    continue;
                }
                $value = $summary[$bench][$stat] ?? null;
                if ($value !== null) {
                    $hasAtLeastOneConfig = true;
                }
                $values[$bench][$config] = $value;
            }
        }
        if (\in_array('clang', $benches)) {
            if (str_starts_with($stat, 'size-')) {
                $value = $fullSummary->stage1Stats[$stat] ?? null;
                if ($value !== null) {
                    $hasAtLeastOneConfig = true;
                }
                $values['clang']['stage1'] = $value;
            }
            $value = $fullSummary->stage2Stats[$stat] ?? null;
            if ($value !== null) {
                $hasAtLeastOneConfig = true;
            }
            $values['clang']['stage2'] = $value;
        }

        if ($hasAtLeastOneConfig) {
            $hashes[] = $hash;
            $dates[] = $commit['commit_date'];
            foreach ($values as $bench => $benchValues) {
                foreach ($benchValues as $config => $value) {
                    $data[$bench][$config][] = $value;
                }
            }
            $i = 0;
        }
    }
    return [$hashes, $dates, $data];
}

function transformData(array &$data, callable $fn): void {
    foreach ($data as $bench => &$benchValues) {
        foreach ($benchValues as $config => &$values) {
            $values = $fn($values);
        }
    }
}

function makeRelative(array $values): array {
    $first = null;
    $newValues = [];
    foreach ($values as $value) {
        if ($value === null) {
            $newValues[] = null;
        } else if ($first === null) {
            $first = $value;
            $newValues[] = 0.0;
        } else {
            $newValues[] = ($value - $first) / $first * 100;
        }
    }
    return $newValues;
}

function smooth(array $values, int $window): array {
    $newValues = [];
    foreach ($values as $i => $value) {
        if ($value === null) {
            $newValues[] = null;
        } else {
            // TODO: Can be done more efficiently, esp. if we stick to simple average.
            $sum = 0.0;
            $count = 0;
            for ($j = -$window; $j <= $window; $j++) {
                //$w = $window - abs($j) + 1;
                if (isset($values[$i+$j])) {
                    $sum += $values[$i+$j];
                    $count++;
                    //$sum += $w * $values[$i+$j];
                    //$count += $w;
                }
            }
            $newValues[] = $sum / $count;
        }
    }
    return $newValues;
}

ob_start("ob_gzhandler");

$commits = getMainCommits();
$stat = getStringParam('stat') ?? DEFAULT_METRIC;
$bench = getStringParam('bench') ?? 'all';
$relative = isset($_GET['relative']);
$startDateStr = getStringParam('startDate') ?? '';
$interval = getIntParam('interval') ?? 1;
$configs = getConfigsParam('configs') ?? DEFAULT_CONFIGS;
$width = getIntParam('width') ?? 480;
$smoothWindow = getIntParam('smoothWindow') ?? 0;

if (empty($_SERVER['QUERY_STRING'])) {
    // By default, show relative metrics for last month.
    $relative = true;
    $startDateStr = (new DateTime('-1 month'))->format('Y-m-d');
}

$startDate = $startDateStr ? new DateTime($startDateStr) : null;

printHeader();

echo "<form>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
echo "<label>Relative (percent): <input type=\"checkbox\" name=\"relative\""
   . ($relative ? " checked" : "") . " /></label>\n";
echo "<label>Start date: <input name=\"startDate\" value=\"" . h($startDateStr) . "\" /></label>\n";
if ($bench !== 'all') {
    echo "<input type=\"hidden\" name=\"bench\" value=\"" . h($bench) . "\" />\n";
}
if ($interval !== 1) {
    echo "<input type=\"hidden\" name=\"interval\" value=\"" . h($interval) . "\" />\n";
}
if ($configs !== DEFAULT_CONFIGS) {
    echo "<input type=\"hidden\" name=\"configs\" value=\"" . h(implode(',', $configs)) . "\" />\n";
}
echo "<input type=\"submit\" value=\"Go\" />\n";
$longTermUrl = makeUrl("graphs.php", [
    "startDate" => "2021-02-04",
    "interval" => "100",
    "relative" => "on",
]);
echo "<a href=\"" . $longTermUrl . "\">Long term view</a>\n";
echo "</form>\n";
echo "<hr />\n";
echo "<script src=\"//cdnjs.cloudflare.com/ajax/libs/dygraph/2.1.0/dygraph.min.js\"></script>\n";
echo "<link rel=\"stylesheet\" href=\"//cdnjs.cloudflare.com/ajax/libs/dygraph/2.1.0/dygraph.min.css\" />\n";
echo "<style>
.dygraph-legend {
  top: 15em !important;
}
</style>
<script>
graphs = [];
function toggleVisibility(el) {
    for (g of graphs) {
        if (g.numColumns() > 2) {
            g.setVisibility(parseInt(el.id), el.checked);
        }
    }
}
</script>\n";
echo "<form>\n";
foreach ($configs as $i => $config) {
    echo "<label><input type=\"checkbox\" id=\"$i\" checked autocomplete=\"off\" onClick=\"toggleVisibility(this)\" /> ";
    echo h($config) . "</label>\n";
}
echo "</form>\n";

if ($bench == 'all') {
    $benches = BENCHES;
    $benches[] = 'clang';
} else {
    if (!in_array($bench, BENCHES) && $bench != 'clang') {
        die("Unknown benchmark " . h($bench));
    }
    $benches = [$bench];
}

if ($smoothWindow < 0 || $smoothWindow > 100) {
    echo "<div class=\"warning\">Invalid smoothing window</div>\n";
    return;
}

[$hashes, $dates, $data] = getData($commits, $configs, $benches, $stat, $interval, $startDate);
if ($smoothWindow != 0) {
    transformData($data, fn($values) => smooth($values, $smoothWindow));
}
if ($relative) {
    transformData($data, 'makeRelative');
}

$numValues = count($hashes);
foreach ($data as $bench => $benchValues) {
    $csv = "Date," . implode(",", array_keys($benchValues)) . "\n";
    for ($i = 0; $i < $numValues; ++$i) {
        $csv .= $dates[$i];
        foreach ($benchValues as $config => $values) {
            $csv .= ',' . $values[$i];
        }
        $csv .= "\n";
    }

    $encodedCsv = json_encode($csv);
    $encodedStat = json_encode($stat);
    echo <<<HTML
<div style="float: left; margin: 1em;">
<h4>$bench:</h4>
<div id="graph-$bench"></div>
<script>
graphs.push(new Dygraph(document.getElementById('graph-$bench'), $encodedCsv, {
    includeZero: true,
    connectSeparatedPoints: true,
    width: $width,
    axes: {
        x: {
            axisLabelWidth: 67,
        }
    },
    clickCallback: function(e, x, points) {
        var idx = points[0].idx;
        if (idx == 0) {
            return;
        }
        var hash = hashes[idx];
        var prevHash = hashes[idx - 1];
        var url = 'compare.php?from=' + prevHash + '&to=' + hash + '&stat=' + $encodedStat;
        if (e.button == 1) {
            window.open(url, '_blank');
        } else {
            window.location.href = url;
        }
    },
}));
</script>
</div>
HTML;
}

$encodedHashes = json_encode($hashes); 
echo <<<HTML
<script>
hashes = $encodedHashes;
</script>

HTML;

printFooter();
