rm -rf ./llvm-test-suite-build
# Hack to use our own timeit script
cp ./timeit.sh ./llvm-test-suite/tools
./cmake_llvm_test_suite.sh
ninja -C./llvm-test-suite-build
