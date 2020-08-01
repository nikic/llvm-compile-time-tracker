#!/usr/bin/env bash
if [[ $# -lt 2 || $1 != "--summary" ]]; then
    echo "Missing --summary option"
    exit 1
fi

OUT=$2
shift 2

TIME_OUT=$OUT
PERF_OUT="$OUT.perfstats"
LC_ALL=C \
    time -f "%M;%e" -o $TIME_OUT \
    perf stat -x \; -o $PERF_OUT \
        -e instructions \
        -e instructions:u \
        -e cycles \
        -e task-clock \
        -e branches \
        -e branch-misses \
        $@
