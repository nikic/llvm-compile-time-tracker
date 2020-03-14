<?php

$dataDir = __DIR__ . '/../data';
$commitsFile = $dataDir . '/commits.json';

$stat = $_GET['stat'] ?? 'instructions';

echo <<<'STYLE'
<style>
* { font-family: monospace; }
table { border-spacing: 1em .1em; }
</style>
STYLE;
echo "\n";

$branchCommits = json_decode(file_get_contents($commitsFile), true);
foreach ($branchCommits as $branch => $commits) {
    $titles = null;
    $rows = [];
    $lastMetrics = null;
    foreach ($commits as $commit) {
        $hash = $commit['hash'];
        $summary = getSummary($dataDir, $hash);
        $row = [formatHash($hash)];
        if ($summary) {
            if (!$titles) {
                $titles = array_merge(['Commit'], array_keys($summary));
            }
            $metrics = array_column($summary, $stat);
            foreach ($metrics as $i => $value) {
                $prevValue = $lastMetrics[$i] ?? null;
                if ($prevValue !== null) {
                    $perc = ($value / $prevValue - 1.0) * 100;
                    $row[] = formatMetric($value, $stat) . ' (' . formatPerc($perc) . ')';
                } else {
                    $row[] = formatMetric($value, $stat);
                }
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
    return $shortHash;
}

function formatPerc(float $value): string {
    $threshold = 0.5;
    $str = sprintf('%+.2f%%', $value);
    if ($value > $threshold) {
        return "<span style=\"color: red\">$str</span>";
    } else if ($value < -$threshold) {
        return "<span style=\"color: green\">$str</span>";
    } else {
        return $str;
    }
}

function formatMetric(float $value, string $metric): string {
    switch ($metric) {
    case 'instructions':
        $m = $value / (1000 * 1000);
        return round($m) . 'M';
    case 'task-clock':
        return round($value) . 'ms';
    case 'max-rss':
        $k = $value / 1024;
        return round($k) . 'KiB';
    default:
        return (string) $value;
    }
}

function h(string $str): string {
    return htmlspecialchars($str);
}

function getSummary(string $dataDir, string $hash): ?array {
    $file = "$dataDir/experiments/$hash/O3/summary.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
}
