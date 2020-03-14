sudo apt update
sudo apt install -y \
    binutils-dev ccache composer cmake g++ \
    linux-tools-common linux-tools-generic linux-cloud-tools-generic \
    php-cli php-zip \
    ninja-build tcl
git clone https://github.com/llvm/llvm-project.git 
git clone https://github.com/llvm/llvm-test-suite.git 
git clone git@github.com:nikic/llvm-compile-time-data.git data
