rm -rf ./llvm-test-suite-build
# Hack to use our own timeit script
cp ./timeit.sh ./llvm-test-suite/tools
./cmake_llvm_test_suite.sh $1
# By default ninja will use one more thread than the number of cores.
# Limit to number of cores to keep task-clock and wall-time close.
ninja -j`nproc` -C./llvm-test-suite-build
