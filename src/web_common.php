<?php

require __DIR__ . '/common.php';

function formatPerc(float $value, bool $isInteresting): string {
    $str = sprintf('%+.2f%%', $value);
    if ($isInteresting) {
        $color = $value > 0 ? "red" : "green";
        return "<span style=\"color: $color\">$str</span>";
    } else {
        return $str;
    }
}

function formatMetric(?float $value, string $metric): string {
    if ($value === null) {
        return '---';
    }

    switch ($metric) {
    case 'instructions':
    case 'cycles':
    case 'branches':
    case 'branch-misses':
        $m = $value / (1000 * 1000);
        return round($m) . 'M';
    case 'task-clock':
        return round($value) . 'ms';
    case 'max-rss':
    case 'size-total':
    case 'size-text':
    case 'size-data':
    case 'size-bss':
        $k = $value / 1024;
        return round($k) . 'KiB';
    case 'wall-time':
        return sprintf('%.2f', $value);
    default:
        return (string) $value;
    }
}

function formatMetricDiff(float $newValue, ?float $oldValue, string $stat, ?float $stddev): string {
    if ($oldValue !== null) {
        $perc = ($newValue / $oldValue - 1.0) * 100;
        $isInteresting = false;
        if ($stddev !== null) {
            $threshold = 5 * $stddev;
            $isInteresting = abs($newValue - $oldValue) >= $threshold;
        }
        return formatMetric($newValue, $stat) . ' (' . formatPerc($perc, $isInteresting) . ')';
    } else {
        return formatMetric($newValue, $stat) . ' (------)';
    }
}

function h(string $str): string {
    return htmlspecialchars($str);
}

function printHeader() {
    echo <<<'STYLE'
<!DOCTYPE html>
<style>
* { font-family: monospace; }
table { border-spacing: 1em .1em; margin: 0 -1em; }
td { text-align: right; }
</style>
<nav>
<a href="index.php">Index</a> |
<a href="graphs.php">Graphs</a> |
<a href="compare.php">Compare</a>
</nav>
<hr />
STYLE;
}

function printStatSelect(string $stat) {
    $opt = function(string $name) use($stat) {
        $selected = $name === $stat ? " selected" : "";
        echo "<option$selected>$name</option>\n";
    };
    echo "<select name=\"stat\">\n";
    // Not listed: context-switches, cpu-migrations, page-faults
    $opt("instructions");
    $opt("max-rss");
    $opt("task-clock");
    $opt("cycles");
    $opt("branches");
    $opt("branch-misses");
    $opt("wall-time");
    echo "</select>\n";
}
