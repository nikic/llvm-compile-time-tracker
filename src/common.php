<?php

const DATA_DIR = __DIR__ . '/../data';
const CONFIGS = ['O3', 'ReleaseThinLTO', 'ReleaseLTO-g', 'O0-g'];
const BENCHES = [
    'geomean',
    'kimwitu++',
    'sqlite3',
    'consumer-typeset',
    'Bullet',
    'tramp3d-v4',
    'mafft',
    'ClamAV',
    'lencod',
    'SPASS',
    '7zip',
];

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

function getDirForHash(string $hash): string {
    return DATA_DIR . '/experiments/' . substr($hash, 0, 2) . '/' . substr($hash, 2);
}

function hasBuildError(string $hash): bool {
    return file_exists(getDirForHash($hash) . '/error');
}

function addGeomean(array $summary): array {
    $statValues = [];
    foreach ($summary as $bench => $stats) {
        foreach ($stats as $stat => $value) {
            $statValues[$stat][] = $value;
        }
    }
    $summary['geomean'] = array_map('geomean', $statValues);
    return $summary;
}

function getSummary(string $hash, string $config): ?array {
    $file = getDirForHash($hash) . "/summary.json";
    if (!file_exists($file)) {
        return null;
    }

    $summary = json_decode(file_get_contents($file), true);
    if (!isset($summary[$config])) {
        return null;
    }

    return addGeomean($summary[$config]);
}

function getStats(string $hash, string $config): ?array {
    $file = getDirForHash($hash) . "/stats.msgpack.gz";
    if (!file_exists($file)) {
        return null;
    }

    $stats = msgpack_unpack(gzdecode(file_get_contents($file)));
    return $stats[$config] ?? null;
}

function getClangSizeSummary(string $hash): ?array {
    $file = getDirForHash($hash) . "/size.json";
    if (!file_exists($file)) {
        return null;
    }

    return json_decode(file_get_contents($file), true);
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
