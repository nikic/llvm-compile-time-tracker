rm -rf ./llvm-project-build && \
./cmake_llvm_project.sh && \
time ninja -C./llvm-project-build
