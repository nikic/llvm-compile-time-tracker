<?php

require __DIR__ . '/../src/web_common.php';
printHeader();

?>
<p>
The source code for the compiler time tracker is available at <a href="https://github.com/nikic/llvm-compile-time-tracker">nikic/llvm-compile-time-tracker</a>.
The raw data is available at <a href="https://github.com/nikic/llvm-compile-time-data">nikic/llvm-compile-time-data</a>.
</p>
<p>
If you are an LLVM contributor who regularly does compile-time sensitive work and would like to test the compile-time impact of your patches before they land, contact me at nikic@php.net with a link to your GitHub fork of llvm-project. Once your fork is added, any branches starting with `perf/` will get measured.
</p>
