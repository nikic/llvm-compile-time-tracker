<?php

require __DIR__ . '/../src/web_common.php';
$commitsFile = DATA_DIR . '/commits.json';

$config = $_GET['config'] ?? 'O3';
$stat = $_GET['stat'] ?? 'instructions';

printStyle();

echo "<form>\n";
echo "<label>Config: "; printConfigSelect($config); echo "</label>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
echo "<input type=\"submit\" value=\"Go\" />\n";
echo "</form>\n";

$branchCommits = json_decode(file_get_contents($commitsFile), true);
foreach ($branchCommits as $branch => $commits) {
    $titles = null;
    $rows = [];
    $lastMetrics = null;
    $lastHash = null;
    foreach ($commits as $commit) {
        $hash = $commit['hash'];
        $summary = getSummary($hash, $config);
        $row = [formatCommit($commit)];
        if ($summary) {
            if (!$titles) {
                $titles = array_merge(['Commit'], array_keys($summary), ['geomean']);
            }
            $metrics = array_column($summary, $stat);
            $metrics = addGeoMean($metrics);
            foreach ($metrics as $i => $value) {
                $prevValue = $lastMetrics[$i] ?? null;
                $row[] = formatMetricDiff($value, $prevValue, $stat);
            }
            if ($lastHash !== null) {
                $row[] = "<a href=\"compare.php?from=$lastHash&to=$hash&stat=" . h($stat) . "\">C</a>";
            }
            $lastMetrics = $metrics;
            $lastHash = $hash;
        }
        $rows[$hash] = $row;
    }

    echo "<h4>", h($branch), ":</h4>\n";
    echo "<table>\n";
    echo "<tr>\n";
    foreach ($titles as $title) {
        echo "<th>$title</th>\n";
    }
    echo "</tr>\n";
    foreach (array_reverse($rows) as $hash => $row) {
        echo "<tr>\n";
        foreach ($row as $value) {
            echo "<td>$value</td>\n";
        }
        echo "</tr>\n";
    }
    echo "</table>\n";
}

function formatCommit(array $commit): string {
    $hash = $commit['hash'];
    $shortHash = substr($hash, 0, 10);
    $title = h($commit['subject']);
    return "<a href=\"https://github.com/llvm/llvm-project/commit/$hash\" title=\"$title\">$shortHash</a>";
}

function printConfigSelect(string $name) {
    echo "<select name=\"config\">\n";
    foreach (CONFIGS as $config) {
        $selected = $name === $config ? " selected" : "";
        echo "<option$selected>$config</option>\n";
    }
    echo "</select>\n";
}
