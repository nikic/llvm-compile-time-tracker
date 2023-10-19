BUILD_DIR=/tmp/llvm-project-build-stage2
rm -rf $BUILD_DIR && \
./cmake_llvm_project_stage2.sh && \
./timeit.sh --summary $BUILD_DIR/build.time ninja -j`nproc` -C$BUILD_DIR clang
