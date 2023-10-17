<?php

require __DIR__ . '/src/common.php';
require __DIR__ . '/src/build_log.php';

$configNum = 4;
$from = '7d367bc92bad9211b5d990b999710f7b25fbd907';
$to = null;

$commitsFile = CURRENT_DATA_DIR . '/commits.json';
$summaryStddevFile = __DIR__ . '/stddev_' . $configNum . '.json';
$statsStddevFile = __DIR__ . '/stats_stddev_' . $configNum . '.msgpack';
$percentStddevFile = __DIR__ . '/stddev_percent_' . $configNum . '.json';
$branchCommits = json_decode(file_get_contents($commitsFile), true);
$mainCommits = $branchCommits['origin/main'];

$commits = [];
$foundFirst = false;
foreach ($mainCommits as $commit) {
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
$i = 0;
foreach ($commits as $hash) {
    $summary = getSummaryForHash($hash);
    $stats = getStatsForHash($hash);
    $log = readBuildLog($hash);
    if (!$summary || !$stats || !$log) {
        continue;
    }

    foreach ($summary->data as $config => $configData) {
        foreach ($configData as $bench => $benchData) {
            foreach ($benchData as $stat => $value) {
                $summaryData[$config][$bench][$stat][] = $value;
            }
        }
    }

    foreach ($summary->stage1Stats as $stat => $value) {
        $summaryData['build']['stage1-clang'][$stat][] = $value;
    }

    foreach ($summary->stage2Stats as $stat => $value) {
        $summaryData['build']['stage2-clang'][$stat][] = $value;
    }

    foreach ($stats as $config => $configData) {
        foreach ($configData as $bench => $files) {
            foreach ($files as $file => $fileData) {
                foreach ($fileData as $stat => $value) {
                    $statsData[$config][$file][$stat][] = $value;
                }
            }
        }
    }

    foreach ($log as $file => $entry) {
        foreach (BUILD_LOG_METRICS as $stat) {
            $value = $entry->getStat($stat);
            if ($value !== null) {
                $statsData['stage2-clang'][$file][$stat][] = $value;
            }
        }
    }

    if (++$i % 1000 == 0) {
        echo "Read data for $i commits...\n";
    }
}

echo "Read data for $i commits.\n";
echo "Computing stddevs...\n";
$summaryStddevs = [];
$percentStddevs = [];
foreach ($summaryData as $config => $configData) {
    foreach ($configData as $bench => $benchData) {
        foreach ($benchData as $stat => $statData) {
            $stddev = stddev($statData);
            $summaryStddevs[$config][$bench][$stat] = $stddev;
            $percentStddevs[$config][$bench][$stat] = $stddev / avg($statData) * 100.0;
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
file_put_contents($percentStddevFile, json_encode($percentStddevs, JSON_PRETTY_PRINT));

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
 * distributions. We do not correct for the mean, because the mean is expected to be zero. This
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

function avg(array $values): float {
    return array_sum($values) / count($values);
}

/*function stddev(array $values): float {
    $avg = avg($values);
    $sqSum = 0.0;
    foreach ($values as $value) {
        $delta = $value - $avg;
        $sqSum += $delta * $delta;
    }
    return sqrt($sqSum / (count($values) - 1));
}*/
