<?php

require __DIR__ . '/src/common.php';
$commitsFile = DATA_DIR . '/commits.json';
$summaryStddevFile = __DIR__ . '/stddev.json';
$statsStddevFile = __DIR__ . '/stats_stddev.msgpack';
$branchCommits = json_decode(file_get_contents($commitsFile), true);
$masterCommits = $branchCommits['origin/master'];
//$from = 'ced0d1f42b39bd93b350b2597ce6587d107c26a7';
$from = '32e90cbcd19a83e20a86bfc1cbf7cec9729e9077';
$to = '5c10c6e0128a69442b55d324f010f94dbe7eebc9';

$commits = [];
$foundFirst = false;
foreach ($masterCommits as $commit) {
    $hash = $commit['hash'];
    if (!$foundFirst) {
        if ($hash !== $from) {
            continue;
        }
        $foundFirst = true;
    }
    $commits[] = $hash;
    if ($hash === $to) {
        break;
    }
}

echo "Reading data...\n";
$summaryData = [];
$statsData = [];
foreach ($commits as $hash) {
    foreach (CONFIGS as $config) {
        $summary = getSummary($hash, $config);
        if ($summary) {
            foreach ($summary as $bench => $stats) {
                foreach ($stats as $stat => $value) {
                    $summaryData[$config][$bench][$stat][] = $value;
                }
            }
        }

        $stats = getStats($hash, $config);
        if ($stats) {
            foreach ($stats as $bench => $files) {
                foreach ($files as $stats) {
                    $file = $stats['file'];
                    foreach ($stats as $stat => $value) {
                        if ($stat === 'file') {
                            continue;
                        }
                        $statsData[$config][$file][$stat][] = $value;
                    }
                }
            }
        }
    }
}

echo "Computing stddevs...\n";
$summaryStddevs = [];
foreach ($summaryData as $config => $configData) {
    foreach ($configData as $bench => $benchData) {
        foreach ($benchData as $stat => $statData) {
            $summaryStddevs[$config][$bench][$stat] = stddev($statData);
        }
    }
}

$statsStddevs = [];
foreach ($statsData as $config => $configData) {
    foreach ($configData as $file => $fileData) {
        foreach ($fileData as $stat => $statData) {
            $statsStddevs[$config][$file][$stat] = stddev($statData);
        }
    }
}

file_put_contents($summaryStddevFile, json_encode($summaryStddevs, JSON_PRETTY_PRINT));
file_put_contents($statsStddevFile, msgpack_pack($statsStddevs));

function diffs(array $values): array {
    $lastValue = null;
    $diffs = [];
    foreach ($values as $value) {
        if ($lastValue !== null) {
            $diffs[] = $value - $lastValue;
        }
        $lastValue = $value;
    }
    return $diffs;
}

/* We compute the standard deviation of the values based on the standard deviation of the
 * differences here, which are connected by a factor of sqrt(2) as a sum of independent normal
 * distributions. We do not correct for the mean, because the mean if expected to be zero. This
 * trick allows us to work on values that potentially have significant changes, because these
 * jumps will show up as individual large values in the differences, and don't have an overly
 * large impact on the final result. */
function diffs_stddev(array $diffs): float {
    $sqSum = 0.0;
    foreach ($diffs as $diff) {
        $sqSum += $diff * $diff;
    }
    $stddev = sqrt($sqSum / (count($diffs) - 1));
    return $stddev;
}

function stddev(array $values): float {
    $diffs = diffs($values);

    // Compute preliminary stddev, discard diffs >= 5sigma, and then recompute.
    $changed = true;
    while ($changed) {
        $changed = false;
        $stddev = diffs_stddev($diffs);
        foreach ($diffs as $i => $diff) {
            if (abs($diff) >= 5 * $stddev) {
                unset($diffs[$i]);
                $changed = true;
            }
        }
    }

    // Taking abs() here to avoid negative zero.
    return abs($stddev / sqrt(2));
}

/*function avg(array $values): float {
    return array_sum($values) / count($values);
}

function stddev(array $values): float {
    $avg = avg($values);
    $sqSum = 0.0;
    foreach ($values as $value) {
        $delta = $value - $avg;
        $sqSum += $delta * $delta;
    }
    return sqrt($sqSum / (count($values) - 1));
}*/
