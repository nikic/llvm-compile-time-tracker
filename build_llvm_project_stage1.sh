rm -rf ./llvm-project-build-stage1 && \
./cmake_llvm_project_stage1.sh && \
time ninja -C./llvm-project-build-stage1 clang
