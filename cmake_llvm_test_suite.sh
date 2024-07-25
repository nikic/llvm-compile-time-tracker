cmake -GNinja -H./llvm-test-suite -B/tmp/llvm-test-suite-build \
    -C./llvm-test-suite/cmake/caches/$1.cmake \
    -DCMAKE_C_COMPILER=/tmp/llvm-project-build-$2/bin/clang \
    -DTESTSUITE_USE_LINKER=/tmp/llvm-project-build-$2/bin/ld.lld \
    -DCMAKE_RANLIB=/tmp/llvm-project-build-$2/bin/llvm-ranlib \
    -DCMAKE_AR=/tmp/llvm-project-build-$2/bin/llvm-ar \
    -DTEST_SUITE_USE_PERF=true \
    -DTEST_SUITE_SUBDIRS=CTMark \
    -DTEST_SUITE_RUN_BENCHMARKS=false \
    -DTEST_SUITE_COLLECT_CODE_SIZE=false
