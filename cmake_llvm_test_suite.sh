cmake -GNinja -H./llvm-test-suite -B./llvm-test-suite-build \
    -DOPTFLAGS="$2" \
    -C./llvm-test-suite/cmake/caches/$1.cmake \
    -DCMAKE_C_COMPILER=$PWD/llvm-project-build/bin/clang \
    -DTEST_SUITE_USE_PERF=true \
    -DTEST_SUITE_SUBDIRS=CTMark \
    -DTEST_SUITE_RUN_BENCHMARKS=false \
    -DTEST_SUITE_COLLECT_CODE_SIZE=false \
