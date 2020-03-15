<?php

const DATA_DIR = __DIR__ . '/../data';

function getSummary(string $hash, string $config): ?array {
    $file = DATA_DIR . "/experiments/$hash/$config/summary.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
}

function getStats(string $hash, string $config): ?array {
    $file = DATA_DIR . "/experiments/$hash/$config/stats.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
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
    case 'cycles':
    case 'branches':
    case 'branch-misses':
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

function formatMetricDiff(float $newValue, ?float $oldValue, string $stat): string {
    if ($oldValue !== null) {
        $perc = ($newValue / $oldValue - 1.0) * 100;
        return formatMetric($newValue, $stat) . ' (' . formatPerc($perc) . ')';
    } else {
        return formatMetric($newValue, $stat) . ' (------)';
    }
}

function h(string $str): string {
    return htmlspecialchars($str);
}

function printStyle() {
    echo <<<'STYLE'
<style>
* { font-family: monospace; }
table { border-spacing: 1em .1em; }
td { text-align: right; }
</style>

STYLE;
}
