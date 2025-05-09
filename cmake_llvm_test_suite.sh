toolchain=""
if [ -n "$3" ]; then
    toolchain="--toolchain $PWD/$3.cmake"
fi
cmake -GNinja -H./llvm-test-suite -B/tmp/llvm-test-suite-build \
    -C./llvm-test-suite/cmake/caches/$1.cmake \
    -DCMAKE_C_COMPILER=/tmp/llvm-project-build-$2/bin/clang \
    -DTEST_SUITE_USE_PERF=true \
    -DTEST_SUITE_SUBDIRS=CTMark \
    -DTEST_SUITE_RUN_BENCHMARKS=false \
    -DTEST_SUITE_COLLECT_CODE_SIZE=false \
    $toolchain
