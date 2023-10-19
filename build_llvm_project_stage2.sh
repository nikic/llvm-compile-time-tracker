rm -rf ./llvm-project-build-stage2 && \
./cmake_llvm_project_stage2.sh && \
time ninja -C./llvm-project-build-stage2 clang LLVMgold.so
