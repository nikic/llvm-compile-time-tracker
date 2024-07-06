sudo apt update
sudo apt install -y \
    binutils-dev ccache composer cmake g++ \
    linux-tools-common linux-tools-generic linux-cloud-tools-generic \
    php-cli php-msgpack php-zip \
    ninja-build tcl time
git clone https://github.com/llvm/llvm-project.git 
git clone https://github.com/llvm/llvm-test-suite.git 
git clone git@github.com:nikic/llvm-compile-time-data-1.git data
git config --global user.name "LLVM Compile Time Bot"
git config --global user.email "nikita.ppv+llvm-ct@gmail.com"
composer install
sudo useradd -m lctt-runner
sudo -u lctt-runner ccache -M 50G
# Set kernel.perf_event_paranoid=0 in /etc/sysctl.conf
