<?php

require __DIR__ . '/../src/web_common.php';
$commitsFile = DATA_DIR . '/commits.json';
$branchCommits = json_decode(file_get_contents($commitsFile), true);
$commits = $branchCommits['origin/master'];

$stat = $_GET['stat'] ?? 'instructions';

ob_start("ob_gzhandler");

printHeader();

echo "<form>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
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

$benches = [
    'geomean',
    'kimwitu++',
    'sqlite3',
    'consumer-typeset',
    'Bullet',
    'tramp3d-v4',
    'mafft',
    'ClamAV',
    'lencod',
    'SPASS',
    '7zip',
];

$hashes = [];
foreach ($benches as $bench) {
    $csv = "Date," . implode(",", CONFIGS) . "\n";
    foreach ($commits as $commit) {
        $hash = $commit['hash'];
        $hasAtLeastOneConfig = false;
        $line = $commit['commit_date'];
        foreach (CONFIGS as $config) {
            $summary = getSummary($hash, $config);
            if (isset($summary[$bench][$stat])) {
                $hasAtLeastOneConfig = true;
                $line .= ',' . $summary[$bench][$stat];
            } else {
                $line .= ',';
            }
        }
        $line .= "\n";
        if ($hasAtLeastOneConfig) {
            $csv .= $line;
            if ($bench === $benches[0]) {
                $hashes[] = $hash;
            }
        }
    }

    $encodedCsv = json_encode($csv);
    $encodedStat = json_encode($stat);
    echo <<<HTML
<div style="float: left; margin: 1em;">
<h4>$bench:</h4>
<div id="graph-$bench"></div>
<script>
g = new Dygraph(document.getElementById('graph-$bench'), $encodedCsv, {
    includeZero: true,
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
