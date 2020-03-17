cmake -GNinja -H./llvm-project/llvm -B./llvm-project-build \
    -DLLVM_ENABLE_PROJECTS="clang" \
    -DLLVM_TARGETS_TO_BUILD="X86" \
    -DLLVM_BUILD_TOOLS=false \
    -DLLVM_APPEND_VC_REV=false \
    -DCMAKE_BUILD_TYPE=Release \
    -DLLVM_CCACHE_BUILD=true \
    -DLLVM_USE_LINKER=gold \
    -DLLVM_BINUTILS_INCDIR=/usr/include
