<?php

require __DIR__ . '/../src/web_common.php';
$commitsFile = DATA_DIR . '/commits.json';

$stat = $_GET['stat'] ?? 'instructions';

printStyle();

$branchCommits = json_decode(file_get_contents($commitsFile), true);
foreach ($branchCommits as $branch => $commits) {
    $titles = null;
    $rows = [];
    $lastMetrics = null;
    foreach ($commits as $commit) {
        $hash = $commit['hash'];
        $summary = getSummary($hash);
        $row = [formatHash($hash)];
        if ($summary) {
            if (!$titles) {
                $titles = array_merge(['Commit'], array_keys($summary));
            }
            $metrics = array_column($summary, $stat);
            foreach ($metrics as $i => $value) {
                $prevValue = $lastMetrics[$i] ?? null;
                $row[] = formatMetricDiff($value, $prevValue, $stat);
            }
            $lastMetrics = $metrics;
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

function formatHash(string $hash): string {
    $shortHash = substr($hash, 0, 10);
    return "<a href=\"https://github.com/llvm/llvm-project/commit/$hash\">$shortHash</a>";
}
