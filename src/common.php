<?php

const DATA_DIR = __DIR__ . '/../data';
const CONFIGS = ['O3', 'ReleaseThinLTO', 'ReleaseLTO-g'];

function array_column_with_keys(array $array, $column): array {
    $result = [];
    foreach ($array as $key => $subArray) {
        if (isset($subArray[$column])) {
            $result[$key] = $subArray[$column];
        }
    }
    return $result;
}

function array_key_union(array $array1, array $array2): array {
    return array_keys(array_merge($array1, $array2));
}

function geomean(array $stats): float {
    return pow(array_product($stats), 1/count($stats));
}

function getSummary(string $hash, string $config): ?array {
    $file = DATA_DIR . "/experiments/$hash/$config/summary.json";
    if (!file_exists($file)) {
        return null;
    }

    $summary = json_decode(file_get_contents($file), true);
    $statValues = [];
    foreach ($summary as $bench => $stats) {
        foreach ($stats as $stat => $value) {
            $statValues[$stat][] = $value;
        }
    }
    $summary['geomean'] = array_map('geomean', $statValues);
    return $summary;
}

function getStats(string $hash, string $config): ?array {
    $file = DATA_DIR . "/experiments/$hash/$config/stats.msgpack.gz";
    if (file_exists($file)) {
        return msgpack_unpack(gzdecode(file_get_contents($file)));
    }
    return null;
}

function getStddevData(): array {
    return json_decode(file_get_contents(__DIR__ . '/../stddev.json'), true);
}

function getStddev(array $data, string $config, string $bench, string $stat): ?float {
    return $data[$config][$bench][$stat] ?? null;
}

function getPerFileStddevData(): array {
    return msgpack_unpack(file_get_contents(__DIR__ . '/../stats_stddev.msgpack'));
}
