<?php

function aggregateData(array $statsList): array {
    $aggStats = [];
    foreach ($statsList as $stats) {
        // The file name is not a statistic.
        $file = $stats['file'];
        unset($stats['file']);

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

        $stats = $perfStats + $timeStats + $sizeStats + ['file' => $file];
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
    exec("size $objectName 2>&1", $output, $returnCode);
    if ($returnCode !== 0) {
        // Silently ignore invalid objects.
        // We might be calling size on a bitcode LTO object.
        return [];
    }

    return parseSizeStats(implode("\n", $output));
}
