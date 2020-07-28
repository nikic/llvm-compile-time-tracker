<?php

const DATA_DIR = __DIR__ . '/../data';
const CONFIGS = ['O3', 'ReleaseThinLTO', 'ReleaseLTO-g', 'O0-g'];
const REAL_BENCHES = [
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
const BENCHES = [
    'geomean',
    ...REAL_BENCHES,
];
const BENCHES_GEOMEAN_LAST = [
    ...REAL_BENCHES,
    'geomean',
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

class Summary {
    public int $config;
    public array $clang_size;
    public array $data;

    public static function fromArray(array $data): Summary {
        $summary = new Summary;
        $summary->config = $data['config'];
        $summary->clang_size = $data['clang_size'];
        $summary->data = $data['data'];
        return $summary;
    }

    public function hasConfig(string $config): bool {
        return isset($this->data[$config]);
    }
}

function getSummaryForHash(string $hash): ?Summary {
    $file = getDirForHash($hash) . "/summary.json";
    if (!file_exists($file)) {
        return null;
    }

    return Summary::fromArray(json_decode(file_get_contents($file), true));
}

function writeSummaryForHash(string $hash, Summary $summary): void {
    $file = getDirForHash($hash) . "/summary.json";
    file_put_contents($file, json_encode($summary, JSON_PRETTY_PRINT));
}

function getStatsForHash(string $hash): array {
    $file = getDirForHash($hash) . "/stats.msgpack.gz";
    if (!file_exists($file)) {
        return [];
    }

    return msgpack_unpack(gzdecode(file_get_contents($file)));
}

function writeStatsForHash(string $hash, array $stats): void {
    $file = getDirForHash($hash) . "/stats.msgpack.gz";
    file_put_contents($file, gzencode(msgpack_pack($stats), 9));
}

function getSummary(string $hash, string $config): ?array {
    $summary = getSummaryForHash($hash);
    return $summary->data[$config] ?? null;
}

function getStats(string $hash, string $config): ?array {
    $stats = getStatsForHash($hash);
    return $stats[$config] ?? null;
}

function getClangSizeSummary(string $hash): ?array {
    $summary = getSummaryForHash($hash);
    if ($summary === null) {
        return null;
    }

    return $summary->clang_size;
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
