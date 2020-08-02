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
    public int $configNum;
    public array $clang_size;
    public array $data;

    public function __construct(int $config, array $clang_size, array $data) {
        $this->configNum = $config;
        $this->clang_size = $clang_size;
        $this->data = $data;
    }

    public static function fromArray(array $data): Summary {
        return new Summary($data['config'], $data['clang_size'], $data['data']);
    }

    public function toArray(): array {
        return [
            'config' => $this->configNum,
            'clang_size' => $this->clang_size,
            'data' => $this->data,
        ];
    }

    public function getConfig(string $config): ?array {
        return $this->data[$config] ?? null;
    }

    public function getConfigStat(string $config, string $stat): ?array {
        $data = $this->getConfig($config);
        if ($data === null) {
            return null;
        }
        return array_column_with_keys($data, $stat);
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
    file_put_contents($file, json_encode($summary->toArray(), JSON_PRETTY_PRINT));
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

class StdDevManager {
    private array $summaryData = [];
    private array $statsData = [];

    private function initSummaryData(int $configNum): void {
        if (!isset($this->summaryData[$configNum])) {
            $path = __DIR__ . "/../stddev_$configNum.json";
            $this->summaryData[$configNum] = file_exists($path)
                ? json_decode(file_get_contents($path), true)
                : null;
        }
    }

    private function initStatsData(int $configNum): void {
        if (!isset($this->statsData[$configNum])) {
            $path = __DIR__ . "/../stats_stddev_$configNum.msgpack";
            $this->summaryData[$configNum] = file_exists($path)
                ? msgpack_unpack(file_get_contents($path))
                : null;
        }
    }

    public function getBenchStdDev(
        int $configNum, string $config, string $bench, string $stat
    ): ?float {
        $this->initSummaryData($configNum);
        return $this->summaryData[$configNum][$config][$bench][$stat] ?? null;
    }

    public function getFileStdDev(
        int $configNum, string $config, string $file, string $stat
    ): ?float {
        $this->initStatsData($configNum);
        return $this->summaryData[$configNum][$config][$file][$stat] ?? null;
    }
}