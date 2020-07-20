<?php

require __DIR__ . '/common.php';

set_exception_handler(function(Throwable $e) {
    echo h($e->getMessage());
});

function formatPerc(float $value, float $interestingness): string {
    if ($value === 0.0) {
        return "      ";
    }

    $formattedValue = sprintf('%+.2f%%', $value);

    $minInterestingness = 3.0;
    $maxInterestingness = 4.0;
    if ($interestingness >= $minInterestingness) {
        // Map interestingness to [0, 1]
        $interestingness = min($interestingness, $maxInterestingness);
        $interestingness -= $minInterestingness;
        $interestingness /= $maxInterestingness - $minInterestingness;
        $alpha = 0.4 * $interestingness;
        if ($value > 0.0) {
            $color = "rgba(255, 0, 0, $alpha)";
        } else {
            $color = "rgba(0, 255, 0, $alpha)";
        }
        return "<span style=\"background-color: $color\">$formattedValue</span>";
    }
    return $formattedValue;
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
    case 'size-file':
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

function formatMetricDiff(
        ?float $newValue, ?float $oldValue, string $stat, ?float $stddev): string {
    if ($oldValue !== null && $newValue !== null) {
        $perc = $oldValue !== 0.0 ? ($newValue / $oldValue - 1.0) * 100 : 0.0;
        $interestingness = 0.0;
        if ($stddev !== null) {
            if ($stddev === 0.0) {
                // Avoid division by zero. Could use fdiv() in PHP 8.
                $interestingness = INF;
            } else {
                // Correct by sqrt(2) because we want the stddev of differences.
                $interestingness = abs($newValue - $oldValue) / $stddev / sqrt(2);
            }
        }
        return formatMetric($newValue, $stat) . ' (' . formatPerc($perc, $interestingness) . ')';
    } else {
        return formatMetric($newValue, $stat) . '         ';
    }
}

function formatHash(string $hash): string {
    return "<a href=\"https://github.com/llvm/llvm-project/commit/" . urlencode($hash) . "\">"
         . h($hash) . "</a>";
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
.warning {
    font-weight: bold; background-color: rgba(255, 0, 0, 0.3); padding: .3em; margin: .5em 0;
}
</style>
<nav>
<a href="index.php">Index</a> |
<a href="graphs.php">Graphs</a> |
<a href="compare.php">Compare</a>
</nav>
<hr />
STYLE;
}

function printFooter() {
    $dt = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
    echo "<!-- Generated in {$dt}s -->";
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
    $opt("size-file");
    echo "</select>\n";
}

function makeUrl(string $page, array $queryParams): string {
    return $page . '?' . http_build_query($queryParams);
}

function getStringParam(string $name): ?string {
    if (!isset($_GET[$name])) {
        return null;
    }

    $value = $_GET[$name];
    if (!is_string($value)) {
        throw new Exception("Query parameter \"$name\" is not a string");
    }
    return $value;
}

function isCommitHash(string $value): bool {
    return (bool) preg_match('/^[0-9a-f]{40}$/', $value);
}
