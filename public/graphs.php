<?php

require __DIR__ . '/../src/web_common.php';
$commitsFile = DATA_DIR . '/commits.json';
$branchCommits = json_decode(file_get_contents($commitsFile), true);
$commits = $branchCommits['origin/master'];

$stat = $_GET['stat'] ?? 'instructions';
$bench = $_GET['bench'] ?? 'all';
$relative = isset($_GET['relative']);

ob_start("ob_gzhandler");

printHeader();

echo "<form>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
echo "<label>Relative (percent): <input type=\"checkbox\" name=\"relative\""
   . ($relative ? " checked" : "") . " /></label>\n";
if ($bench !== 'all') {
    echo "<input type=\"hidden\" name=\"bench\" value=\"" . h($bench) . "\" />\n";
}
echo "<input type=\"submit\" value=\"Go\" />\n";
echo "</form>\n";
echo "<hr />\n";

echo "<script src=\"//cdnjs.cloudflare.com/ajax/libs/dygraph/2.1.0/dygraph.min.js\"></script>\n";
echo "<link rel=\"stylesheet\" href=\"//cdnjs.cloudflare.com/ajax/libs/dygraph/2.1.0/dygraph.min.css\" />\n";
echo "<style>
.dygraph-legend {
  top: 15em !important;
}
</style>\n";

if ($bench == 'all') {
    $benches = BENCHES;
} else {
    if (!in_array($bench, BENCHES) && $bench != 'clang') {
        die("Unknown benchmark " . h($bench));
    }
    $benches = [$bench];
}

$hashes = [];
$data = [];
$firstData = [];
foreach ($benches as $bench) {
    $csv[$bench] = "Date," . implode(",", CONFIGS) . "\n";
}
foreach ($commits as $commit) {
    $hasAtLeastOneConfig = false;
    $hash = $commit['hash'];
    $lines = [];
    foreach ($benches as $bench) {
        $lines[$bench] = $commit['commit_date'];
    }
    if ($bench == 'clang') {
        $summary = getClangSizeSummary($hash);
        $value = $summary[$stat] ?? null;
        if ($value !== null) {
            if ($relative) {
                if (!isset($firstData[$bench])) {
                    $firstData[$bench] = $value;
                }
                $firstValue = $firstData[$bench];
                $value = ($value - $firstValue) / $firstValue * 100;
            }
            $hasAtLeastOneConfig = true;
        }
        $lines[$bench] .= ',' . $value;
    } else {
        $fullSummary = getSummaryForHash($hash);
        foreach (CONFIGS as $config) {
            $summary = $fullSummary->data[$config] ?? [];
            foreach ($benches as $bench) {
                if (isset($summary[$bench][$stat])) {
                    $value = $summary[$bench][$stat];
                    if ($relative) {
                        if (!isset($firstData[$bench][$config])) {
                            $firstData[$bench][$config] = $value;
                        }
                        $firstValue = $firstData[$bench][$config];
                        $value = ($value - $firstValue) / $firstValue * 100;
                    }

                    $lines[$bench] .= ',' . $value;
                    $hasAtLeastOneConfig = true;
                } else {
                    $lines[$bench] .= ',';
                }
            }
        }
    }
    if ($hasAtLeastOneConfig) {
        $hashes[] = $hash;
        foreach ($benches as $bench) {
            $csv[$bench] .= $lines[$bench] . "\n";
        }
    }
}

foreach ($benches as $bench) {
    $encodedCsv = json_encode($csv[$bench]);
    $encodedStat = json_encode($stat);
    echo <<<HTML
<div style="float: left; margin: 1em;">
<h4>$bench:</h4>
<div id="graph-$bench"></div>
<script>
g = new Dygraph(document.getElementById('graph-$bench'), $encodedCsv, {
    includeZero: true,
    connectSeparatedPoints: true,
    clickCallback: function(e, x, points) {
        var idx = points[0].idx;
        if (idx == 0) {
            return;
        }
        var hash = hashes[idx];
        var prevHash = hashes[idx - 1];
        var url = 'compare.php?from=' + prevHash + '&to=' + hash + '&stat=' + $encodedStat;
        window.location.href = url;
    },
});
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
