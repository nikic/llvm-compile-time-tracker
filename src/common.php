<?php

const DATA_DIR = __DIR__ . '/../data';
const CONFIGS = ['O3', 'ReleaseThinLTO', 'ReleaseLTO-g'];

function array_column_with_keys(array $array, $column): array {
    return array_combine(array_keys($array), array_column($array, $column));
}
