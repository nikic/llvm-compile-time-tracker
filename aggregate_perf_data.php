<?php

if ($argc < 2) {
    throw new Exception("Expected directory as argument");
}

$dir = $argv[1];
$rawData = readRawData($dir);
$data = array_map('aggregateData', $rawData);
var_dump($data);

function aggregateData(array $statsList): array {
    $aggStats = [];
    foreach ($statsList as $stats) {
        foreach ($stats as $name => $stat) {
            if ($name === 'command' || $name === 'file') {
                continue;
            }

            assert(is_float($stat));
            if (!isset($aggStats[$name])) {
                $aggStats[$name] = 0.0;
            }
            $aggStats[$name] += $stat;
        }
    }
    return $aggStats;
}

function readRawData(string $dir): array {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $projectData = [];
    foreach ($it as $file) {
        $pathName = $file->getPathName();
        if (!preg_match('~CTMark/([^/]+)/(.+)\.time\.perfstats$~', $pathName, $matches)) {
            // Make sure we didn't make any incorrect assumptions about file names.
            if (preg_match('~\.time\.perfstats$~', $pathName)) {
                throw new Exception("Unexpected file name: $pathName\n");
            }
            continue;
        }

        list(, $project, $file) = $matches;
        $perfContents = file_get_contents($pathName);
        $timeContents = file_get_contents(str_replace('.perfstats', '', $pathName));

        try {
            $perfStats = parsePerfStats($perfContents);
            $timeStats = parseTimeStats($timeContents);
        } catch (Exception $e) {
            echo $pathName, ":\n";
            echo $perfContents, "\n";
            echo $timeContents, "\n";
            throw $e;
        }

        $stats = $perfStats + $timeStats + ['file' => $file];
        if (!isset($projectData[$project])) {
            $projectData[$project] = [];
        }
        $projectData[$project][] = $stats;
    }
    return $projectData;
}

function parsePerfStats(string $str): array {
    if (!preg_match_all('~^([0-9.]+);[^;]*;([^;]+);~m', $str, $matches, PREG_SET_ORDER)) {
        throw new Exception("Failed to match perf stats");
    }

    $stats = [];
    foreach ($matches as list(, $value, $stat)) {
        $stats[$stat] = (float) $value;
    }
    return $stats;
}

function parseTimeStats(string $str): array {
    list($maxRss, $wallTime) = explode(';', trim($str));
    return [
        'max-rss' => (float) $maxRss,
        'wall-time' => (float) $wallTime,
    ];
}
