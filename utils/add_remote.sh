#!/bin/bash
git -C llvm-project remote add $1 https://github.com/$1/llvm-project.git
git -C llvm-project config remote.$1.fetch "+refs/heads/perf/*:refs/remotes/$1/perf/*"
