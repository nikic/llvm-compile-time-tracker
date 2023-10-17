<?php

require __DIR__ . '/../src/web_common.php';
require __DIR__ . '/../src/build_log.php';

$commit = $_GET['commit'] ?? null;
if (hasBuildError($commit)) {
    echo "Build error.\n";
    return;
}

$data = readBuildLog($commit);
if ($data === null) {
    echo "No data.\n";
    return;
}

class Threads {
    public array $threads = [];

    public function alloc(LogEntry $e) {
        foreach ($this->threads as $id => &$end) {
            if ($end < $e->start) {
                $end = $e->end;
                return $id;
            }
        }
        $this->threads[] = $e->end;
        return \count($this->threads) - 1;
    }
}

// Sort by reverse start.
uasort($data, fn(LogEntry $a, LogEntry $b) => $a->start <=> $b->start);

$threads = new Threads;
$result = [];
foreach ($data as $name => $entry) {
    $tid = $threads->alloc($entry);
    $result[] = [
        'name' => $name,
        'cat' => 'targets',
        'ph' => 'X',
        'ts' => $entry->start * 1000,
        'dur' => ($entry->end - $entry->start) * 1000,
        'pid' => 0,
        'tid' => $tid,
    ];
}

header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"trace.json\"");
echo json_encode($result);
