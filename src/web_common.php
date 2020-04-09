<?php

require __DIR__ . '/common.php';

function formatPerc(float $value): string {
    if ($value === 0.0) {
        return "      ";
    }

    return sprintf('%+.2f%%', $value);
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
        $m = $value / 1024;
        return round($m) . 'MiB';
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

function formatMetricDiffCell(
        ?float $newValue, ?float $oldValue, string $stat, ?float $stddev): string {
    if ($oldValue !== null && $newValue !== null) {
        $perc = ($newValue / $oldValue - 1.0) * 100;
        $extra = "";
        if ($stddev !== null) {
            $interestingness = abs($newValue - $oldValue) / $stddev;
            $minInterestingness = 4.0;
            $maxInterestingness = 5.0;
            if ($interestingness >= $minInterestingness) {
                // Map interestingness to [0, 1]
                $interestingness = min($interestingness, $maxInterestingness);
                $interestingness -= $minInterestingness;
                $interestingness /= $maxInterestingness - $minInterestingness;
                $alpha = 0.5 * $interestingness;
                if ($oldValue < $newValue) {
                    $color = "rgba(255, 0, 0, $alpha)";
                } else {
                    $color = "rgba(0, 255, 0, $alpha)";
                }
                $extra = " style=\"background-color: $color\"";
            }
        }
        return "<td$extra>" . formatMetric($newValue, $stat) . ' (' . formatPerc($perc) . ')</td>';
    } else {
        return '<td>' . formatMetric($newValue, $stat) . '         </td>';
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
td { text-align: right; white-space: pre; }
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
    $opt("size-total");
    $opt("size-text");
    $opt("size-data");
    $opt("size-bss");
    echo "</select>\n";
}
