rm -rf ./llvm-test-suite-build
# Hack to use our own timeit script
cp ./timeit.sh ./llvm-test-suite/tools
# Annoyingly, the default O0-g configuration doesn't respect OPTFLAGS
cp O0-g.cmake ./llvm-test-suite/cmake/caches
./cmake_llvm_test_suite.sh $1 $2
# By default ninja will use nproc + 2 threads.
# Limit to nproc - 1 to reduce noise.
ninja -j$((`nproc`-1)) -C./llvm-test-suite-build
