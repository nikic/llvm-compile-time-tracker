rm -rf /tmp/llvm-test-suite-build
./cmake_llvm_test_suite.sh $1 $2 $3
# By default ninja will use nproc + 2 threads.
# Limit to nproc - 1 to reduce noise.
ninja -j7 -C/tmp/llvm-test-suite-build
