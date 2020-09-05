rm -rf ./llvm-test-suite-build
# Hack to use our own timeit script
cp ./timeit.sh ./llvm-test-suite/tools
cp NewPM-O3.cmake ./llvm-test-suite/cmake/caches
./cmake_llvm_test_suite.sh $1
# By default ninja will use nproc + 2 threads.
# Limit to nproc - 1 to reduce noise.
ninja -j$((`nproc`-1)) -C./llvm-test-suite-build
