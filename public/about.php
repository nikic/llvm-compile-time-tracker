<?php

require __DIR__ . '/../src/web_common.php';
printHeader();

?>
<p>
The source code for the compiler time tracker is available at <a href="https://github.com/nikic/llvm-compile-time-tracker">nikic/llvm-compile-time-tracker</a>.
The raw data is available at <a href="https://github.com/nikic/llvm-compile-time-data">nikic/llvm-compile-time-data</a>.
</p>

<h3>Pre-Commit Measurements</h3>
<p>
If you are an LLVM contributor who regularly does compile-time sensitive work and would like to test the compile-time impact of your patches before they land, contact me at llvm@npopov.com with a link to your GitHub fork of llvm-project. Once your fork is added, any branches starting with `perf/` will get measured.
</p>

<h3>Reproducing</h3>
<p>
The compile time tracker tests the CTMark portion of the <a href="https://llvm.org/docs/TestSuiteGuide.html">LLVM test-suite</a> against specific cached CMake configurations. You can set up a test-suite build as follows:
</p>
<pre>
git clone https://github.com/llvm/llvm-test-suite.git
mkdir build
cd build
cmake .. -G Ninja \
    -C ../cmake/caches/$CONFIG.cmake \
    -DCMAKE_C_COMPILER=$PATH_TO_CLANG
</pre>
<p>
The $CONFIGs used by the tracker are O3, ReleaseThinLTO, ReleaseLTO-g and O0-g.
</p>
<p>
Next, you will want to pick out a single file with a particularly large regression by enabling the "Per-file details" checkbox on the comparison page. Let's assume we pick CMakeFiles/lencod.dir/rdopt.c.o. The compiler invocation can be obtained using `ninja -v lencod`, after which it can be modified to prepend your favorite profiling tool. For example:
</p>

<pre>
valgrind --tool=callgrind $PATH_TO_CLANG -DNDEBUG  -O3   -w -Werror=date-time -fcommon -D__USE_LARGEFILE64 -D_FILE_OFFSET_BITS=64 -MD -MT MultiSource/Applications/JM/lencod/CMakeFiles/lencod.dir/rdopt_coding_state.c.o -MF MultiSource/Applications/JM/lencod/CMakeFiles/lencod.dir/rdopt_coding_state.c.o.d -o MultiSource/Applications/JM/lencod/CMakeFiles/lencod.dir/rdopt_coding_state.c.o   -c ../MultiSource/Applications/JM/lencod/rdopt_coding_state.c
</pre>

<h3>Configurations</h3>

Compilers:

<ul>
    <li><strong>host</strong>: gcc 11.4</li>
    <li><strong>stage1</strong>:
        clang compiled using host compiler (release build)</li>
    <li><strong>stage2</strong>:
        clang compiled using stage1 compiler (release build with ThinLTO)</li>
</ul>

<p>
Displayed statistics for clang generally refer to the stage2 build. The stage1 build uses ccache and as such does not produce stable timing results.
</p>

Tested configurations:

<ul>
    <li><strong>stage1-O3</strong>:
        -O3, stage1 clang, native x86_64 target</li>
    <li><strong>stage1-ReleaseThinLTO</strong>:
        -O3 -flto=thin, stage1 clang, native x86_64 target</li>
    <li><strong>stage1-ReleaseLTO-g</strong>:
        -O3 -flto -g, stage1 clang, native x86_64 target</li>
    <li><strong>stage1-O0-g</strong>:
        -O0 -g, stage1 clang, native x86_64 target</li>
    <li><strong>stage1-aarch64-O3</strong>:
        -O3, stage1 clang, cross-compilation from x86_64 to aarch64</li>
    <li><strong>stage1-aarch64-O0-g</strong>:
        -O0 -g, stage1 clang, cross-compilation from x86_64 to aarch64</li>
    <li><strong>stage2-O3</strong>:
        -O3, stage2 clang, native x86_64 target</li>
    <li><strong>stage2-O0-g</strong>:
        -O0 -g, stage2 clang, native x86_64 target</li>
</ul>
