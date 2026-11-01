#!/usr/bin/env bash
#
# NurRouterOS installer ISO builder.
#
# Produces a single hybrid ISO that boots on BIOS (El Torito, no emulation) and
# on UEFI (El Torito EFI boot image) and carries:
#
#   /boot/vmlinuz                 appliance kernel
#   /boot/initramfs.cpio.gz       installer initramfs (owns PID 1)
#   /boot/grub/grub.cfg           boot menu
#   /payload/*.img.gz             the published disk images
#   /payload/SHA256SUMS           their checksums
#
# The ISO never installs by itself: nur-install always requires an explicitly
# named target disk and a second confirmation token.
#
# This script only reads ImageBuilder output and writes into output/<version>.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

OPENWRT_VERSION="${OPENWRT_VERSION:-24.10.0}"
OPENWRT_TARGET="${OPENWRT_TARGET:-x86}"
OPENWRT_SUBTARGET="${OPENWRT_SUBTARGET:-64}"
OPENWRT_PROFILE="${OPENWRT_PROFILE:-generic}"

IMAGE_PREFIX="openwrt-${OPENWRT_VERSION}-${OPENWRT_TARGET}-${OPENWRT_SUBTARGET}-${OPENWRT_PROFILE}"

INSTALLER_DIR="${ROOT_DIR}/appliance/installer"
OUTPUT_DIR="${OUTPUT_DIR:-${ROOT_DIR}/output/${OPENWRT_VERSION}}"
APPLIANCE_DIR="${OUTPUT_DIR}/appliance"
PAYLOAD_SOURCE_DIR="${APPLIANCE_DIR}/payload"
ISO_DIR="${APPLIANCE_DIR}/iso"

WORK_DIR="${ROOT_DIR}/work/installer"
INITRAMFS_ROOT="${WORK_DIR}/initramfs-root"
ISO_ROOT="${WORK_DIR}/iso-root"

# Finalized after reading product VERSION below.
ISO_NAME=""

require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Missing required command: ${command_name}" >&2
        exit 1
    fi
}

find_imagebuilder_dir() {
    find "${ROOT_DIR}/work" -maxdepth 1 -mindepth 1 -type d \
        -name 'openwrt-imagebuilder-*' 2>/dev/null | head -n 1
}

require_command tar
require_command cpio
require_command gzip
require_command sha256sum
require_command mkfs.fat

IMAGEBUILDER_DIR="$(find_imagebuilder_dir)"

if [ -z "${IMAGEBUILDER_DIR}" ]; then
    echo "Unable to locate the extracted ImageBuilder directory." >&2
    echo "Run ./build.sh first." >&2
    exit 1
fi

GRUB_ASSET_DIR="${IMAGEBUILDER_DIR}/staging_dir/target-${OPENWRT_TARGET}_64_musl/image/grub2"
IMAGEBUILDER_HOST_BIN="${IMAGEBUILDER_DIR}/staging_dir/host/bin"

if [ ! -d "${GRUB_ASSET_DIR}" ]; then
    echo "Unable to locate the grub2 assets at ${GRUB_ASSET_DIR}." >&2
    exit 1
fi

# mkisofs is not always present; xorriso can act as one.
if command -v mkisofs >/dev/null 2>&1; then
    MKISOFS=(mkisofs)
elif command -v genisoimage >/dev/null 2>&1; then
    MKISOFS=(genisoimage)
elif command -v xorriso >/dev/null 2>&1; then
    MKISOFS=(xorriso -as mkisofs)
else
    echo "Missing required command: mkisofs, genisoimage or xorriso" >&2
    exit 1
fi

# mtools is shipped inside the ImageBuilder when the host has no copy.
resolve_mtool() {
    local tool="$1"

    if command -v "${tool}" >/dev/null 2>&1; then
        command -v "${tool}"
        return 0
    fi

    if [ -x "${IMAGEBUILDER_HOST_BIN}/${tool}" ]; then
        printf '%s\n' "${IMAGEBUILDER_HOST_BIN}/${tool}"
        return 0
    fi

    echo "Missing required command: ${tool}" >&2
    return 1
}

MMD="$(resolve_mtool mmd)"
MCOPY="$(resolve_mtool mcopy)"

KERNEL_PATH="${OUTPUT_DIR}/${IMAGE_PREFIX}-kernel.bin"
ROOTFS_TARBALL="${OUTPUT_DIR}/${IMAGE_PREFIX}-rootfs.tar.gz"

if [ ! -f "${KERNEL_PATH}" ]; then
    echo "Unable to locate the kernel at ${KERNEL_PATH}. Run ./build.sh first." >&2
    exit 1
fi

if [ ! -f "${ROOTFS_TARBALL}" ]; then
    echo "Unable to locate the rootfs at ${ROOTFS_TARBALL}. Run ./build.sh first." >&2
    exit 1
fi

if [ ! -f "${PAYLOAD_SOURCE_DIR}/SHA256SUMS" ]; then
    echo "Unable to locate the installer payloads in ${PAYLOAD_SOURCE_DIR}." >&2
    echo "Run ./appliance/make-images.sh first." >&2
    exit 1
fi

INSTALLER_VERSION="$(tr -d '[:space:]' < "${INSTALLER_DIR}/VERSION")"
PRODUCT_VERSION="$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION" 2>/dev/null || echo "${INSTALLER_VERSION}")"
ISO_NAME="nurrouteros-${PRODUCT_VERSION}-installer.iso"

# ---------------------------------------------------------------------------
# 1. Installer initramfs
# ---------------------------------------------------------------------------

echo "==> Building the installer initramfs (product ${PRODUCT_VERSION}, installer ${INSTALLER_VERSION})"

rm -rf "${INITRAMFS_ROOT}"
mkdir -p "${INITRAMFS_ROOT}"

echo "  extracting ${ROOTFS_TARBALL##*/}"
tar -xzf "${ROOTFS_TARBALL}" -C "${INITRAMFS_ROOT}"

mkdir -p \
    "${INITRAMFS_ROOT}/dev" \
    "${INITRAMFS_ROOT}/proc" \
    "${INITRAMFS_ROOT}/sys" \
    "${INITRAMFS_ROOT}/tmp" \
    "${INITRAMFS_ROOT}/run" \
    "${INITRAMFS_ROOT}/mnt/cdrom" \
    "${INITRAMFS_ROOT}/mnt/target" \
    "${INITRAMFS_ROOT}/usr/sbin" \
    "${INITRAMFS_ROOT}/etc/nurrouter-installer"

install -m 0755 "${INSTALLER_DIR}/init" "${INITRAMFS_ROOT}/init"
install -m 0755 "${INSTALLER_DIR}/nur-devnodes" "${INITRAMFS_ROOT}/usr/sbin/nur-devnodes"
install -m 0755 "${INSTALLER_DIR}/nur-install" "${INITRAMFS_ROOT}/usr/sbin/nur-install"
install -m 0755 "${INSTALLER_DIR}/nur-recover" "${INITRAMFS_ROOT}/usr/sbin/nur-recover"
install -m 0644 "${INSTALLER_DIR}/VERSION" "${INITRAMFS_ROOT}/etc/nurrouter-installer/version"

{
    echo "NurRouterOS installer"
    echo "product version   : ${PRODUCT_VERSION}"
    echo "installer version : ${INSTALLER_VERSION}"
    echo "openwrt version   : ${OPENWRT_VERSION}"
    echo "profile           : ${IMAGE_PREFIX}"
    echo "kernel            : ${IMAGE_PREFIX}-kernel.bin"
    echo "built at (UTC)    : $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo
    echo "installable targets:"
    while read -r checksum name; do
        [ -n "${name:-}" ] || continue
        printf '  %-34s sha256 %s\n' "${name}" "${checksum}"
    done < "${PAYLOAD_SOURCE_DIR}/SHA256SUMS"
    echo
    echo "kernel command line options:"
    echo "  nur.mode=install|recovery   select the program that owns the console"
    echo "  nur.disk=/dev/sdX           explicit target disk (required unattended)"
    echo "  nur.confirm=ERASE-sdX       required confirmation token for that disk"
    echo "  nur.firmware=auto|bios|uefi target firmware (default: auto)"
    echo "  nur.rootfs=ext4|squashfs    payload root filesystem (default: ext4)"
    echo "  nur.grow=yes|no             grow the rootfs to fill the disk (ext4)"
    echo "  nur.interactive=0|1         disable prompting (default: 1)"
    echo "  nur.autoreboot=0|1          reboot when finished (default: 0)"
    echo "  nur.dryrun=1                validate only, never write"
    echo
    echo "Installation always requires an explicitly named disk and a second"
    echo "confirmation. No disk is ever selected automatically."
} > "${INITRAMFS_ROOT}/etc/nurrouter-installer/manifest"

INITRAMFS_PATH="${APPLIANCE_DIR}/installer-initramfs.cpio.gz"
mkdir -p "${APPLIANCE_DIR}"

echo "  packing ${INITRAMFS_PATH##*/}"
(
    cd "${INITRAMFS_ROOT}"
    find . -print0 | cpio --null -o -H newc 2>/dev/null | gzip -n -9 > "${INITRAMFS_PATH}"
)

echo "  initramfs: $(numfmt --to=iec "$(stat -c%s "${INITRAMFS_PATH}")" 2>/dev/null || stat -c%s "${INITRAMFS_PATH}")"

# ---------------------------------------------------------------------------
# 2. ISO tree
# ---------------------------------------------------------------------------

echo
echo "==> Assembling the ISO tree"

rm -rf "${ISO_ROOT}"
mkdir -p "${ISO_ROOT}/boot/grub" "${ISO_ROOT}/payload"

install -m 0644 "${KERNEL_PATH}" "${ISO_ROOT}/boot/vmlinuz"
install -m 0644 "${INITRAMFS_PATH}" "${ISO_ROOT}/boot/initramfs.cpio.gz"
install -m 0644 "${INSTALLER_DIR}/grub.cfg" "${ISO_ROOT}/boot/grub/grub.cfg"

# BIOS El Torito core image: the 512-byte boot image followed by the core.
cat "${GRUB_ASSET_DIR}/cdboot.img" "${GRUB_ASSET_DIR}/eltorito.img" \
    > "${ISO_ROOT}/boot/grub/eltorito.img"

# UEFI El Torito boot image: a FAT12 floppy holding EFI/BOOT/BOOTX64.EFI.
mkfs.fat -C "${ISO_ROOT}/boot/grub/isoboot.img" -S 512 1440 >/dev/null
"${MMD}" -i "${ISO_ROOT}/boot/grub/isoboot.img" ::/efi ::/efi/boot
"${MCOPY}" -i "${ISO_ROOT}/boot/grub/isoboot.img" \
    "${GRUB_ASSET_DIR}/iso-bootx64.efi" ::/efi/boot/bootx64.efi

# Firmware that scans the ISO9660 tree for an EFI system partition entry needs a
# plain copy as well; this also makes the media usable on removable USB media.
mkdir -p "${ISO_ROOT}/EFI/BOOT"
install -m 0644 "${GRUB_ASSET_DIR}/iso-bootx64.efi" "${ISO_ROOT}/EFI/BOOT/BOOTX64.EFI"

cp "${PAYLOAD_SOURCE_DIR}"/*.img.gz "${ISO_ROOT}/payload/"
cp "${PAYLOAD_SOURCE_DIR}/SHA256SUMS" "${ISO_ROOT}/payload/SHA256SUMS"

echo "  ISO payloads:"
ls -1 "${ISO_ROOT}/payload" | sed 's/^/    /'

# ---------------------------------------------------------------------------
# 3. ISO image
# ---------------------------------------------------------------------------

echo
echo "==> Writing ${ISO_NAME}"

mkdir -p "${ISO_DIR}"
rm -f "${ISO_DIR}/${ISO_NAME}"

"${MKISOFS[@]}" \
    -R \
    -b boot/grub/eltorito.img -no-emul-boot -boot-info-table \
    -boot-load-size 4 -c boot.cat \
    -eltorito-alt-boot -e boot/grub/isoboot.img -no-emul-boot \
    -V "NURROUTEROS_INSTALLER" \
    -o "${ISO_DIR}/${ISO_NAME}" \
    "${ISO_ROOT}"

(
    cd "${ISO_DIR}"
    sha256sum "${ISO_NAME}" > "${ISO_NAME}.sha256"
)

echo
echo "Installer ISO: ${ISO_DIR}/${ISO_NAME}"
echo "  size    : $(numfmt --to=iec "$(stat -c%s "${ISO_DIR}/${ISO_NAME}")" 2>/dev/null || stat -c%s "${ISO_DIR}/${ISO_NAME}")"
echo "  sha256  : $(awk '{ print $1 }' "${ISO_DIR}/${ISO_NAME}.sha256")"
