rm -rf ./llvm-project-build && \
./cmake_llvm_project.sh && \
time ninja -C./llvm-project-build -j`nproc`
# By default ninja uses nproc + 2 threads.
# Limit to nproc + 1 to reduce memory usage.
