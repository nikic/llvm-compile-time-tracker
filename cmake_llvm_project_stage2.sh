cmake -GNinja -H./llvm-project/llvm -B/tmp/llvm-project-build-stage2 \
    -DCMAKE_BUILD_TYPE=Release \
    -DCMAKE_C_COMPILER=/tmp/llvm-project-build-stage1/bin/clang \
    -DCMAKE_CXX_COMPILER=/tmp/llvm-project-build-stage1/bin/clang++ \
    -DCMAKE_C_COMPILER_LAUNCHER=$PWD/timeit_launcher.sh \
    -DCMAKE_CXX_COMPILER_LAUNCHER=$PWD/timeit_launcher.sh \
    -DCMAKE_C_LINKER_LAUNCHER=$PWD/timeit_launcher.sh \
    -DCMAKE_CXX_LINKER_LAUNCHER=$PWD/timeit_launcher.sh \
    -DCMAKE_RANLIB=/tmp/llvm-project-build-stage1/bin/llvm-ranlib \
    -DCMAKE_AR=/tmp/llvm-project-build-stage1/bin/llvm-ar \
    -DLLVM_ENABLE_PROJECTS="clang" \
    -DLLVM_TARGETS_TO_BUILD="X86" \
    -DLLVM_BUILD_TOOLS=false \
    -DLLVM_INCLUDE_TESTS=false \
    -DLLVM_INCLUDE_BENCHMARKS=false \
    -DLLVM_APPEND_VC_REV=false \
    -DLLVM_USE_LINKER=lld \
    -DLLVM_ENABLE_LTO=Thin \
    -DCLANG_ENABLE_ARCMT=false \
    -DCLANG_ENABLE_STATIC_ANALYZER=false
    #-DLLVM_BINUTILS_INCDIR=/usr/include \
