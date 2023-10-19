#!/usr/bin/env bash
ARGS=("$@")

PERF_OUT=""
while [[ $# -gt 0 ]]; do
  if [ "$1" = "-o" ]; then
    PERF_OUT="$2.time.perfstats"
  fi
  shift
done

if [ -z "$PERF_OUT" ]; then
    "${ARGS[@]}"
else
    LC_ALL=C perf stat -x \; -o $PERF_OUT -e instructions:u "${ARGS[@]}"
fi
