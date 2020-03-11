cmake -GNinja -H./llvm-project/llvm -B./llvm-project-build \
    -DLLVM_ENABLE_PROJECTS="clang;compiler-rt" \
    -DLLVM_TARGETS_TO_BUILD="X86" \
    -DCMAKE_BUILD_TYPE=Release \
    -DLLVM_CMAKE_BUILD=true \
    -DLLVM_USE_LINKER=gold
