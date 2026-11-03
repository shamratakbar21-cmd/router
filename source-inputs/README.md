# Build source inputs

The four upstream archives in this directory match the corresponding entries in `appliance/bras-sources.sha256`. They supply the accel-ppp engine (including its upstream licensing), OpenSSL, PCRE2 and libucontext source. NUR engine changes are in `appliance/patches/`; preserve the applicable upstream terms for this code. Upstream source may include public cryptographic test vectors; none is an operational NUR signing key.

Copy these archives into `downloads/` before running `appliance/build-bras-engine.sh`. The separately downloaded OpenWrt SDK archive must match the pinned SDK hash in that file. The base image input is OpenWrt ImageBuilder 24.10.0 for x86/64. Both are available from https://downloads.openwrt.org/releases/24.10.0/targets/x86/64/ . `build.sh` records the ImageBuilder URL.

The published images combine the pinned OpenWrt kernel with packages from the configured official repositories. Package revisions and licenses are recorded in the release SBOM and installed-package source index. Upstream package source and OpenWrt build recipes are linked there. A clean-checkout bit-identical rebuild and a complete independent corresponding-source compliance audit have not been performed. Public availability is not a new blanket source license for original NUR code.
