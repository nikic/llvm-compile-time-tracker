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
        $contents = file_get_contents($pathName);

	try {
            $stats = parsePerfStats($contents);
        } catch (Exception $e) {
            echo $contents, "\n";
            throw $e;
        }

        $stats['file'] = $file;
        if (!isset($projectData[$project])) {
            $projectData[$project] = [];
        }
        $projectData[$project][] = $stats;
    }
    return $projectData;
}

function parsePerfCommand(string $str): string {
    $pattern = '~Performance counter stats for \'(.*)\':~';
    if (!preg_match($pattern, $str, $matches)) {
        throw new Exception('Failed to match command');
    }
    return $matches[1];
}

function parsePerfStat(string $str, string $stat): float {
    $pattern = "~(\S+)\s+$stat~";
    if (!preg_match($pattern, $str, $matches)) {
        throw new Exception("Failed to match stat \"$stat\"");
    }
    $numberString = $matches[1];
    // Remove thousands separators
    $numberString = str_replace('.', '', $numberString);
    // Replace decimal separator
    $numberString = str_replace(',', '.', $numberString);
    return (float) $numberString;
}

function parsePerfStats(string $str): array {
    return [
        'command' => parsePerfCommand($str),
        'task-clock' => parsePerfStat($str, '(?:msec )?task-clock'),
        'context-switches' => parsePerfStat($str, 'context-switches'),
        'cpu-migrations' => parsePerfStat($str, 'cpu-migrations'),
        'page-faults' => parsePerfStat($str, 'page-faults'),
        'cycles' => parsePerfStat($str, 'cycles'),
        'instructions' => parsePerfStat($str, 'instructions'),
        'branches' => parsePerfStat($str, 'branches'),
        'branch-misses' => parsePerfStat($str, 'branch-misses'),
        'real-time' => parsePerfStat($str, 'seconds time elapsed'),
        'user-time' => parsePerfStat($str, 'seconds user'),
        'system-time' => parsePerfStat($str, 'seconds sys'),
    ];
}
