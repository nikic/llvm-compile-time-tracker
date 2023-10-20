<?php

function summarizeData(array $data): array {
    return addGeomean(array_map('aggregateData', $data));
}

function aggregateData(array $statsList): array {
    $aggStats = [];
    foreach ($statsList as $file => $stats) {
        foreach ($stats as $name => $stat) {
            // When aggregating size stats, we want to report the size of the binary
            // as the aggregate stat, not the sum of all object files.
            if (0 === strpos($name, 'size-') && !preg_match('/\.link$/', $file)) {
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

function average(array $values): float {
    return array_sum($values) / count($values);
}

function averageRawData(array $rawDatas): array {
    $data = [];
    foreach ($rawDatas as $rawData) {
        foreach ($rawData as $bench => $files) {
            foreach ($files as $file => $stats) {
                foreach ($stats as $stat => $value) {
                    $data[$bench][$file][$stat][] = $value;
                }
            }
        }
    }

    $avgData = [];
    foreach ($data as $bench => $benchData) {
        foreach ($benchData as $file => $fileData) {
            $avgData[$bench][$file] = array_map('average', $fileData);
        }
    }

    return $avgData;
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

        if (!preg_match('~(.+?)(?:\.link)?\.time\.perfstats~', $pathName, $matches)) {
            throw new Exception("Unexpected file name: $pathName");
        }
        list(, $objectName) = $matches;

        $perfContents = file_get_contents($pathName);
        $timeContents = file_get_contents(str_replace('.perfstats', '', $pathName));

        try {
            $perfStats = parsePerfStats($perfContents);
            $timeStats = parseTimeStats($timeContents);
            $sizeStats = computeSizeStatsForObject($objectName);
        } catch (Exception $e) {
            echo $pathName, ":\n";
            echo $perfContents, "\n";
            echo $timeContents, "\n";
            throw $e;
        }

        $stats = $perfStats + $timeStats + $sizeStats;
        if (!isset($projectData[$project])) {
            $projectData[$project] = [];
        }
        $projectData[$project][$file] = $stats;
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

function parseSizeStats(string $str): array {
    if (!preg_match('~^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)~m', $str, $matches)) {
        throw new Exception("Failed to match size output");
    }
    return [
        'size-text' => (int) $matches[1],
        'size-data' => (int) $matches[2],
        'size-bss' => (int) $matches[3],
        'size-total' => (int) $matches[4],
    ];
}

function computeSizeStatsForObject(string $objectName): array {
    $stats = ['size-file' => filesize($objectName)];

    exec("size $objectName 2>&1", $output, $returnCode);
    if ($returnCode !== 0) {
        // Silently ignore invalid objects.
        // We might be calling size on a bitcode LTO object.
        return $stats;
    }

    return $stats + parseSizeStats(implode("\n", $output));
}

function parseNinjaLog(string $path, string $sanitizePrefix): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $result = [];
    foreach ($lines as $line) {
        if ($line[0] === '#') {
            continue;
        }

        $parts = explode("\t", $line);
        if (count($parts) < 4) {
            // Malformed line.
            continue;
        }

        [$start, $end, , $file] = $parts;
        if (str_starts_with($file, $sanitizePrefix)) {
            $file = 'build' . substr($file, strlen($sanitizePrefix));
        }
        $result[] = [(int) $start, (int) $end, $file];
    }
    return $result;
}
