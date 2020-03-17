<?php

require __DIR__ . '/../src/web_common.php';
$commitsFile = DATA_DIR . '/commits.json';

$config = $_GET['config'] ?? 'O3';
$stat = $_GET['stat'] ?? 'instructions';
$filterBranch = $_GET['branch'] ?? 'all';

printHeader();

echo "<form>\n";
echo "<label>Config: "; printConfigSelect($config); echo "</label>\n";
echo "<label>Metric: "; printStatSelect($stat); echo "</label>\n";
echo "<input type=\"submit\" value=\"Go\" />\n";
echo "</form>\n";
echo "<hr />\n";

$branchCommits = json_decode(file_get_contents($commitsFile), true);
$stddevs = getStddevData();

echo "<form action=\"compare_selected.php\">\n";
echo "<input type=\"hidden\" name=\"stat\" value=\"" . h($stat) . "\" />\n";
echo "Compare selected: <input type=\"submit\" value=\"Compare\" />\n";
echo "Or click the \"C\" to compare with previous.\n";
foreach ($branchCommits as $branch => $commits) {
    if ($filterBranch !== $branch && $filterBranch !== 'all') {
        continue;
    }

    $titles = null;
    $rows = [];
    $lastMetrics = null;
    $lastHash = null;
    foreach ($commits as $commit) {
        $hash = $commit['hash'];
        $summary = getSummary($hash, $config);
        $row = [];
        if ($summary && $lastHash) {
            $row[] = "<a href=\"compare.php?from=$lastHash&to=$hash&stat=" . h($stat) . "\">C</a>";
        } else {
            $row[] = '';
        }
        if ($summary) {
            $row[] = "<input type=\"checkbox\" name=\"commits[]\" value=\"$hash\" style=\"margin: -1px -0.5em\" />";
        } else {
            $row[] = '';
        }
        $row[] = formatCommit($commit);

        if ($summary) {
            if (!$titles) {
                $titles = array_merge(['', '', 'Commit'], array_keys($summary));
            }
            $metrics = array_column_with_keys($summary, $stat);
            foreach ($metrics as $bench => $value) {
                $stddev = getStddev($stddevs, $config, $bench, $stat);
                $prevValue = $lastMetrics[$bench] ?? null;
                $row[] = formatMetricDiff($value, $prevValue, $stat, $stddev);
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
echo "</form>\n";

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
