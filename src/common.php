<?php

const DATA_DIR = __DIR__ . '/../data';
const CONFIGS = ['O3', 'ReleaseThinLTO', 'ReleaseLTO-g'];

function array_column_with_keys(array $array, $column): array {
    return array_combine(array_keys($array), array_column($array, $column));
}

function getSummary(string $hash, string $config): ?array {
    $file = DATA_DIR . "/experiments/$hash/$config/summary.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
}
