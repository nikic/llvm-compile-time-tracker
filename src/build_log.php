<?php

const BUILD_LOG_METRICS = [
    'instructions:u',
    'wall-time',
    'size-file',
];

class LogEntry {
    public function __construct(
        public int $start,
        public int $end,
        public int $size,
        public int $instructions,
    ) {}

    public function getStat(string $stat): ?int {
        switch ($stat) {
        case 'wall-time':
            return $this->end - $this->start;
        case 'size-file':
            return $this->size ?: null;
        case 'instructions:u':
            return $this->instructions ?: null;
        default:
            return null;
        }
    }
}

function readBuildLog(string $hash) {
    $path = getPathForHash($hash, '/stage2log.gz');
    if ($path === null) {
        return null;
    }

    $contents = gzdecode(file_get_contents($path));
    $result = [];
    foreach (explode("\n", trim($contents)) as $line) {
        [$start, $end, $file, $size, $instructions] = explode("\t", $line);
        $result[$file] = new LogEntry($start, $end, $size, $instructions);
    }

    return $result;
}
