#!/bin/bash
# Pinned experimental musl x86_64 build; caches and toolchain have explicit hashes.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"
sha256sum -c appliance/bras-sources.sha256
sdk="$root/work/openwrt-sdk-24.10.0-x86-64_gcc-13.3.0_musl.Linux-x86_64"
[[ -d "$sdk" ]] || tar --zstd -xf downloads/openwrt-sdk-24.10.0-x86-64.tar.zst -C work
export STAGING_DIR="$sdk/staging_dir/toolchain-x86_64_gcc-13.3.0_musl"
export PATH="$STAGING_DIR/bin:$PATH"
build=$(mktemp -d "$root/work/bras-build-XXXXXXXX")
mkdir "$build/engine" "$build/ucontext" "$build/sysroot"
tar -xzf downloads/openssl-3.0.22.tar.gz -C "$build"
tar -xzf downloads/pcre2-10.42.tar.gz -C "$build"
tar -xzf downloads/accel-ppp-d80cdc6.tar.gz -C "$build/engine"
patch -d "$build/engine" -p1 < appliance/patches/0001-durable-accounting.patch
install -m644 appliance/patches/nur_accounting.h "$build/engine/accel-pppd/radius/"
tar -xzf downloads/libucontext-49e671d.tar.gz -C "$build/ucontext"
(cd "$build/openssl-3.0.22"; ./Configure linux-x86_64 --cross-compile-prefix=x86_64-openwrt-linux-musl- --prefix=/usr --openssldir=/etc/ssl no-tests shared; make -j4; make DESTDIR="$build/sysroot" install_sw)
(cd "$build/pcre2-10.42"; ./configure --host=x86_64-openwrt-linux-musl --prefix=/usr --enable-shared --disable-static --disable-pcre2grep-libz --disable-pcre2grep-libbz2; make -j4; make DESTDIR="$build/sysroot" install)
make -C "$build/ucontext" -j4 CC=x86_64-openwrt-linux-musl-gcc
make -C "$build/ucontext" DESTDIR="$build/sysroot" install
cat > "$build/toolchain.cmake" <<EOF
set(CMAKE_SYSTEM_NAME Linux)
set(CMAKE_SYSTEM_PROCESSOR x86_64)
set(CMAKE_C_COMPILER $STAGING_DIR/bin/x86_64-openwrt-linux-musl-gcc)
set(CMAKE_FIND_ROOT_PATH $build/sysroot/usr $STAGING_DIR)
set(CMAKE_FIND_ROOT_PATH_MODE_PROGRAM NEVER)
set(CMAKE_FIND_ROOT_PATH_MODE_LIBRARY ONLY)
set(CMAKE_FIND_ROOT_PATH_MODE_INCLUDE ONLY)
set(CMAKE_C_FLAGS_INIT "-I$build/sysroot/usr/include -DMUSL")
set(CMAKE_EXE_LINKER_FLAGS_INIT "-L$build/sysroot/usr/lib64 -L$build/sysroot/usr/lib -Wl,-rpath-link,$build/sysroot/usr/lib64")
set(CMAKE_SHARED_LINKER_FLAGS_INIT "-L$build/sysroot/usr/lib64 -L$build/sysroot/usr/lib -Wl,-rpath-link,$build/sysroot/usr/lib64")
EOF
"$sdk/staging_dir/host/bin/cmake" -S "$build/engine" -B "$build/compiled" -DCMAKE_TOOLCHAIN_FILE="$build/toolchain.cmake" -DCMAKE_INSTALL_PREFIX=/usr -DLIB_SUFFIX= -DBUILD_TESTING=OFF -DCMAKE_BUILD_TYPE=Release
make -C "$build/compiled" -j4
make -C "$build/compiled" DESTDIR="$build/install" install
install -m755 "$build/install/usr/sbin/accel-pppd" files/usr/sbin/accel-pppd
mkdir -p files/usr/lib/accel-ppp files/usr/share/accel-ppp
for module in triton pppoe vlan-mon connlimit auth_pap chap-secrets ippool shaper radius log_file; do
    install -m755 "$build/install/usr/lib/accel-ppp/lib$module.so" files/usr/lib/accel-ppp/
done
install -m755 "$build/sysroot/usr/lib/libucontext.so.1" files/usr/lib/accel-ppp/
cp -a "$build/install/usr/share/accel-ppp/radius" files/usr/share/accel-ppp/
printf 'Engine build retained at %s\n' "$build"
