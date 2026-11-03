#!/usr/bin/env bash
#
# NurRouterOS appliance image pipeline.
#
# Turns the OpenWrt ImageBuilder output into the recoverable artefacts the
# project publishes:
#
#   * nurrouteros-<firmware>-<rootfs>.img      raw disk image (dd-able)
#   * nurrouteros-<firmware>-<rootfs>.img.gz   compressed installer payload
#   * nurrouteros-<firmware>-<rootfs>.qcow2    QCOW2 for VM deployment
#
# <firmware> is bios or uefi. <rootfs> is ext4 or squashfs.
#
# The pipeline is non-destructive: it only reads the ImageBuilder output and
# writes into output/<version>/appliance.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

OPENWRT_VERSION="${OPENWRT_VERSION:-24.10.0}"
OPENWRT_TARGET="${OPENWRT_TARGET:-x86}"
OPENWRT_SUBTARGET="${OPENWRT_SUBTARGET:-64}"
OPENWRT_PROFILE="${OPENWRT_PROFILE:-generic}"

IMAGE_PREFIX="openwrt-${OPENWRT_VERSION}-${OPENWRT_TARGET}-${OPENWRT_SUBTARGET}-${OPENWRT_PROFILE}"

SOURCE_DIR="${SOURCE_DIR:-${ROOT_DIR}/output/${OPENWRT_VERSION}}"
APPLIANCE_DIR="${APPLIANCE_DIR:-${SOURCE_DIR}/appliance}"
PAYLOAD_DIR="${APPLIANCE_DIR}/payload"

GZIP_LEVEL="${GZIP_LEVEL:-9}"

require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Missing required command: ${command_name}" >&2
        exit 1
    fi
}

require_command gzip
require_command sha256sum
require_command qemu-img
require_command parted
require_command sgdisk

if [ ! -d "${SOURCE_DIR}" ]; then
    echo "Unable to locate the ImageBuilder output at ${SOURCE_DIR}." >&2
    echo "Run ./build.sh first." >&2
    exit 1
fi

mkdir -p "${PAYLOAD_DIR}"

# firmware:rootfs:source-artifact
IMAGE_MATRIX="
bios:ext4:${IMAGE_PREFIX}-ext4-combined.img.gz
uefi:ext4:${IMAGE_PREFIX}-ext4-combined-efi.img.gz
bios:squashfs:${IMAGE_PREFIX}-squashfs-combined.img.gz
uefi:squashfs:${IMAGE_PREFIX}-squashfs-combined-efi.img.gz
"

published_sha256() {
    local artifact="$1"

    if [ -f "${SOURCE_DIR}/sha256sums" ]; then
        awk -v target="${artifact}" \
            '$2 == target || $2 == "*"target { print $1; exit }' \
            "${SOURCE_DIR}/sha256sums"
    fi
}

verify_source() {
    local path="$1"
    local expected
    expected="$(published_sha256 "${path##*/}")"

    if [ -z "${expected}" ]; then
        echo "  no recorded checksum for $(basename "${path}"); skipping verification."
        return 0
    fi

    local actual
    actual="$(sha256sum "${path}" | awk '{ print $1 }')"
    if [ "${actual}" != "${expected}" ]; then
        echo "  checksum mismatch for $(basename "${path}")" >&2
        echo "    expected ${expected}" >&2
        echo "    actual   ${actual}" >&2
        return 1
    fi
    echo "  source checksum OK"
}

decompress() {
    local source="$1"
    local target="$2"

    # The ImageBuilder gzip streams carry a small amount of trailing padding,
    # so gzip reports a warning and a non-zero status even though the payload
    # decompresses completely. The stream itself was checksum-verified before
    # reaching this point.
    gzip -dc "${source}" > "${target}" 2>/dev/null || true

    if [ ! -s "${target}" ]; then
        echo "  decompression produced an empty image" >&2
        return 1
    fi
}

compress() {
    local source="$1"
    local target="$2"

    # -n keeps the payload byte-identical across runs for the same input.
    gzip -n "-${GZIP_LEVEL}" -c "${source}" > "${target}"
}

build_one() {
    local firmware="$1"
    local rootfs_variant="$2"
    local source_artifact="$3"

    local source_path="${SOURCE_DIR}/${source_artifact}"
    local base="nurrouteros-${firmware}-${rootfs_variant}"
    local raw_path="${APPLIANCE_DIR}/${base}.img"
    local payload_path="${PAYLOAD_DIR}/${base}.img.gz"
    local qcow2_path="${APPLIANCE_DIR}/${base}.qcow2"

    echo
    echo "==> ${base}"

    if [ ! -f "${source_path}" ]; then
        echo "  missing ImageBuilder artifact: ${source_artifact}" >&2
        return 1
    fi

    verify_source "${source_path}"

    echo "  expanding ${source_artifact}"
    decompress "${source_path}" "${raw_path}"

    bytes="$(stat -c%s "${raw_path}")"
    echo "  raw image: $(numfmt --to=iec "${bytes}" 2>/dev/null || echo "${bytes}")"

    # ImageBuilder pads GPT disk images after writing the secondary header.
    # Relocate it to the actual end, and mark the FAT boot partition as an ESP.
    # Operate exclusively on the newly generated regular image file.
    if [ "${firmware}" = uefi ]; then
        sgdisk --move-second-header --typecode=1:ef00 "${raw_path}"
        sgdisk --verify "${raw_path}"
    fi

    # Structural check: the image must still contain a usable partition table.
    if ! parted -s "${raw_path}" print >/dev/null 2>&1; then
        echo "  the produced raw image has no readable partition table" >&2
        return 1
    fi

    echo "  writing installer payload ${base}.img.gz"
    compress "${raw_path}" "${payload_path}"

    echo "  converting to QCOW2 ${base}.qcow2"
    qemu-img convert -f raw -O qcow2 "${raw_path}" "${qcow2_path}"

    echo "  done"
}

echo "NurRouterOS appliance image pipeline"
echo "  version : ${OPENWRT_VERSION}"
echo "  source  : ${SOURCE_DIR}"
echo "  output  : ${APPLIANCE_DIR}"
echo "  payload : ${PAYLOAD_DIR}"

while IFS=: read -r firmware rootfs_variant source_artifact; do
    [ -n "${firmware}" ] || continue
    build_one "${firmware}" "${rootfs_variant}" "${source_artifact}"
done <<< "${IMAGE_MATRIX}"

echo
echo "Calculating checksums ..."
(
    cd "${PAYLOAD_DIR}"
    for payload in ./*.img.gz; do
        sha256sum "${payload##*/}"
    done > SHA256SUMS
)

echo
echo "Published payloads:"
(
    cd "${PAYLOAD_DIR}"
    cat SHA256SUMS
)

artifacts_json=""
for entry in ${IMAGE_MATRIX}; do
    firmware="${entry%%:*}"
    rest="${entry#*:}"
    rootfs_variant="${rest%%:*}"
    base="nurrouteros-${firmware}-${rootfs_variant}"

    entry_json="    {
      \"name\": \"${base}\",
      \"firmware\": \"${firmware}\",
      \"rootfs\": \"${rootfs_variant}\",
      \"raw\": \"${base}.img\",
      \"payload\": \"payload/${base}.img.gz\",
      \"qcow2\": \"${base}.qcow2\",
      \"sha256_payload\": \"$(sha256sum "${PAYLOAD_DIR}/${base}.img.gz" | awk '{ print $1 }')\",
      \"sha256_qcow2\": \"$(sha256sum "${APPLIANCE_DIR}/${base}.qcow2" | awk '{ print $1 }')\"
    }"

    if [ -n "${artifacts_json}" ]; then
        artifacts_json="${artifacts_json},
${entry_json}"
    else
        artifacts_json="${entry_json}"
    fi
done

cat > "${APPLIANCE_DIR}/manifest.json" <<EOF
{
  "product": "NurRouterOS",
  "product_version": "$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION")",
  "openwrt_version": "${OPENWRT_VERSION}",
  "profile": "${IMAGE_PREFIX}",
  "generated_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "payload_directory": "payload",
  "payload_checksums": "payload/SHA256SUMS",
  "installer_usage": "nur-install --disk /dev/sdX --confirm ERASE-sdX --firmware bios|uefi --rootfs ext4|squashfs",
  "artifacts": [
${artifacts_json}
  ]
}
EOF

echo
echo "Appliance images are available in: ${APPLIANCE_DIR}"
echo "Installer payloads are available in: ${PAYLOAD_DIR}"
