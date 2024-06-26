<?php

require __DIR__ . '/common.php';

const DEFAULT_METRIC = 'instructions:u';
const DEFAULT_METRICS = [
    "instructions",
    "instructions:u",
    "max-rss",
    "task-clock",
    "cycles",
    "branches",
    "branch-misses",
    "wall-time",
    "size-total",
    "size-text",
    "size-data",
    "size-bss",
    "size-file",
];

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function(Throwable $e) {
    echo h($e->getMessage() . ' on line ' . $e->getLine());
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

    $metric = str_replace(':u', '', $metric);
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
        return sprintf('%.2fs', $value);
    default:
        return (string) $value;
    }
}

function getInterestingness(float $diff, ?float $stddev): float {
    if ($stddev === null) {
        return 0.0;
    }

    if ($stddev === 0.0) {
        // Avoid division by zero. Could use fdiv() in PHP 8.
        return INF;
    }

    // Correct by sqrt(2) because we want the stddev of differences.
    return abs($diff) / $stddev / sqrt(2);
}

function formatMetricDiff(
        ?float $newValue, ?float $oldValue, string $stat, ?float $stddev): string {
    if ($oldValue !== null && $newValue !== null) {
        $perc = $oldValue !== 0.0 ? ($newValue / $oldValue - 1.0) * 100 : 0.0;
        $interestingness = getInterestingness($newValue - $oldValue, $stddev);
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
<title>LLVM Compile-Time Tracker</title>
<style>
* { font-family: monospace; }
table { border-spacing: 1em .1em; margin: 0 -1em; }
td { text-align: right; white-space: pre; }
p, pre { max-width: 40em; }
pre { margin-left: 2em; white-space: pre-wrap; }
.warning {
    font-weight: bold; background-color: rgba(255, 0, 0, 0.3); padding: .3em; margin: .5em 0;
}
</style>
<nav>
<a href="index.php">Index</a> |
<a href="graphs.php">Graphs</a> |
<a href="compare.php">Compare</a> |
<a href="about.php">About</a>
</nav>
<hr />
STYLE;
}

function printFooter() {
    $dt = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
    $mem = intdiv(memory_get_peak_usage(), 1024 * 1024);
    echo "<!-- Generated in {$dt}s, {$mem}MiB -->";
}

function printSelect(string $name, string $value, array $options) {
    echo "<select name=\"$name\">\n";
    foreach ($options as $option) {
        $selected = $option === $value ? " selected" : "";
        echo "<option$selected>$option</option>\n";
    }
    echo "</select>\n";
}

function printStatSelect(string $stat, array $stats = DEFAULT_METRICS) {
    return printSelect("stat", $stat, $stats);
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

function getIntParam(string $name): ?int {
    $str = getStringParam($name);
    if ($str === null) {
        return null;
    }

    if (!ctype_digit($str)) {
        throw new Exception("Query parameter \"$name\" is not an integer");
    }

    return (int) $str;
}

function getConfigsParam(string $name): ?array {
    $str = getStringParam($name);
    if ($str === null) {
        return null;
    }

    $configs = explode(',', $str);
    foreach ($configs as $config) {
        if (!in_array($config, CONFIGS)) {
            throw new Exception("Unknown config  \"$config\"");
        }
    }

    return $configs;
}

function isCommitHash(string $value): bool {
    return (bool) preg_match('/^[0-9a-f]{40}$/', $value);
}

function reportError(string $hash): void {
    $errorUrl = makeUrl("show_error.php", ["commit" => $hash]);
    echo "<div class=\"warning\">Failed to build commit " . formatHash($hash)
       . " in some configurations (<a href=\"" . h($errorUrl) . "\">Log</a>)</div>\n";
}

function getGitHubCompareUrl(string $fromHash, string $toHash): string {
    return "https://github.com/llvm/llvm-project/compare/"
         . urlencode($fromHash) . "..." . urlencode($toHash);
}
