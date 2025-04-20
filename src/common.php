<?php

const CURRENT_DATA_DIR = __DIR__ . '/../data';
const DATA_DIRS = [
    CURRENT_DATA_DIR,
    __DIR__ . '/../data-0',
];
const DEFAULT_CONFIGS = [
    'stage1-O3',
    'stage1-ReleaseThinLTO',
    'stage1-ReleaseLTO-g',
    'stage1-O0-g',
    'stage1-aarch64-O3',
    'stage1-aarch64-O0-g',
    'stage2-O3',
    'stage2-O0-g',
];
const CONFIGS = [
    ...DEFAULT_CONFIGS,
    'LegacyPM-O3',
    'LegacyPM-ReleaseThinLTO',
    'LegacyPM-ReleaseLTO-g',
    'LegacyPM-O0-g',
];
const CONFIG_RENAMES = [
    'O3' => 'LegacyPM-O3',
    'ReleaseThinLTO' => 'LegacyPM-ReleaseThinLTO',
    'ReleaseLTO-g' => 'LegacyPM-ReleaseLTO-g',
    'O0-g' => 'LegacyPM-O0-g',
    'NewPM-O3' => 'stage1-O3',
    'NewPM-ReleaseThinLTO' => 'stage1-ReleaseThinLTO',
    'NewPM-ReleaseLTO-g' => 'stage1-ReleaseLTO-g',
    'NewPM-O0-g' => 'stage1-O0-g',
];
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

function getDirForHash(string $dataDir, string $hash): string {
    return $dataDir . '/experiments/' . substr($hash, 0, 2) . '/' . substr($hash, 2);
}

function getPathForHash(string $hash, string $file): ?string {
    foreach (DATA_DIRS as $dataDir) {
        $path = getDirForHash($dataDir, $hash) . '/' . $file;
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
}

function hasBuildError(string $hash): bool {
    return getPathForHash($hash, '/error') !== null;
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

function upgradeConfigName(string $config): string {
    return CONFIG_RENAMES[$config] ?? $config;
}

function upgradeConfigNameKeys(array $data): array {
    $newData = [];
    foreach ($data as $config => $configData) {
        $newData[upgradeConfigName($config)] = $configData;
    }
    return $newData;
}

class Summary {
    public int $configNum;
    public array $stage1Stats;
    public array $stage2Stats;
    public array $data;

    public function __construct(
        int $configNum, array $stage1Stats, array $stage2Stats, array $data
    ) {
        $this->configNum = $configNum;
        $this->stage1Stats = $stage1Stats;
        $this->stage2Stats = $stage2Stats;
        $this->data = $data;
    }

    public static function fromArray(array $data): Summary {
        return new Summary(
            $data['config'],
            $data['clang_size'],
            $data['stage2'] ?? [],
            upgradeConfigNameKeys($data['data'])
        );
    }

    public function toArray(): array {
        return [
            'config' => $this->configNum,
            'clang_size' => $this->stage1Stats,
            'stage2' => $this->stage2Stats,
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

    public function getGeomeanStats(string $stat): ?array {
        $result = [];
        foreach ($this->data as $config => $configStats) {
            $result[$config] = $configStats['geomean'][$stat] ?? null;
        }
        return $result;
    }
}

function getSummaryForHash(string $hash): ?Summary {
    $file = getPathForHash($hash, "/summary.json");
    if ($file === null) {
        return null;
    }

    return Summary::fromArray(json_decode(file_get_contents($file), true));
}

function writeSummaryForHash(string $hash, Summary $summary): void {
    $file = getDirForHash(CURRENT_DATA_DIR, $hash) . "/summary.json";
    file_put_contents($file, json_encode($summary->toArray(), JSON_PRETTY_PRINT));
}

function getStatsForHash(string $hash): array {
    $file = getPathForHash($hash, "/stats.msgpack.gz");
    if ($file === null) {
        return [];
    }

    return upgradeConfigNameKeys(msgpack_unpack(gzdecode(file_get_contents($file))));
}

function writeStatsForHash(string $hash, array $stats): void {
    $file = getDirForHash(CURRENT_DATA_DIR, $hash) . "/stats.msgpack.gz";
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

class StdDevManager {
    private array $summaryData = [];
    private array $statsData = [];

    private function initSummaryData(int $configNum): void {
        if (!isset($this->summaryData[$configNum])) {
            $path = __DIR__ . "/../stddev/summary_$configNum.json";
            $this->summaryData[$configNum] = file_exists($path)
                ? upgradeConfigNameKeys(json_decode(file_get_contents($path), true))
                : null;
        }
    }

    private function initStatsData(int $configNum): void {
        if (!isset($this->statsData[$configNum])) {
            $path = __DIR__ . "/../stddev/stats_$configNum.msgpack";
            $this->statsData[$configNum] = file_exists($path)
                ? upgradeConfigNameKeys(msgpack_unpack(file_get_contents($path)))
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
        return $this->statsData[$configNum][$config][$file][$stat] ?? null;
    }
}

// From oldest to newest
function getMainCommits(): iterable {
    foreach (array_reverse(DATA_DIRS) as $dataDir) {
        $commitsFile = $dataDir . '/commits.json';
        $commits = json_decode(file_get_contents($commitsFile), true);
        yield from $commits['origin/main'];
    }
}

// FIXME: Implement this in a way that does not require loading all commits.json files into memory.
function getAllCommits(): array {
    $allCommits = [];
    foreach (array_reverse(DATA_DIRS) as $dataDir) {
        $commitsFile = $dataDir . '/commits.json';
        $branchCommits = json_decode(file_get_contents($commitsFile), true);
        foreach ($branchCommits as $branch => $commits) {
            $existing = $allCommits[$branch] ?? [];
            $allCommits[$branch] = array_merge($existing, $commits);
        }
    }
    return $allCommits;
}
