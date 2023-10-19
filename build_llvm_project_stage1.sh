rm -rf /tmp/llvm-project-build-stage1 && \
./cmake_llvm_project_stage1.sh && \
time ninja -C/tmp/llvm-project-build-stage1 clang lld llvm-ar llvm-ranlib LLVMgold.so
