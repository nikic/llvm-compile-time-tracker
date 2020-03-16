<?php

const DATA_DIR = __DIR__ . '/../data';
const CONFIGS = ['O3', 'ReleaseThinLTO', 'ReleaseLTO-g'];

function array_column_with_keys(array $array, $column): array {
    return array_combine(array_keys($array), array_column($array, $column));
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
