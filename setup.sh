sudo apt update
sudo apt install -y \
    ccache cmake g++ \
    linux-tools-common linux-tools-generic linux-cloud-tools-generic \
    ninja-build tcl
git clone https://github.com/llvm/llvm-project.git 
git clone https://github.com/llvm/llvm-test-suite.git 
