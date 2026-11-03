#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

OPENWRT_VERSION="${OPENWRT_VERSION:-24.10.0}"
OPENWRT_TARGET="${OPENWRT_TARGET:-x86}"
OPENWRT_SUBTARGET="${OPENWRT_SUBTARGET:-64}"
OPENWRT_PROFILE="${OPENWRT_PROFILE:-generic}"
ROOTFS_PARTSIZE="${ROOTFS_PARTSIZE:-512}"
NUR_OVERLAY_ROUTER="${NUR_OVERLAY_ROUTER:-}"
NUR_OVERLAY_SOURCE="${NUR_OVERLAY_SOURCE:-applied}"
NUR_SSH_AUTHORIZED_KEY_FILE="${NUR_SSH_AUTHORIZED_KEY_FILE:-}"

BASE_URL="https://downloads.openwrt.org/releases/${OPENWRT_VERSION}/targets/${OPENWRT_TARGET}/${OPENWRT_SUBTARGET}"
ARCHIVE_NAME="openwrt-imagebuilder-${OPENWRT_VERSION}-${OPENWRT_TARGET}-${OPENWRT_SUBTARGET}.Linux-x86_64.tar.zst"
ARCHIVE_PATH="${ROOT_DIR}/downloads/${ARCHIVE_NAME}"
WORK_DIR="${ROOT_DIR}/work"
OUTPUT_DIR="${OUTPUT_DIR:-${ROOT_DIR}/output/${OPENWRT_VERSION}/${PRODUCT_RELEASE:-$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION")}}"
export OUTPUT_DIR
export SOURCE_DIR="${OUTPUT_DIR}"
OVERLAY_DIR="${ROOT_DIR}/files"
MANAGEMENT_DIR="${ROOT_DIR}/management"
AGENT_DIR="${ROOT_DIR}/agent"

require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Missing required command: ${command_name}" >&2
        exit 1
    fi
}

require_command curl
require_command tar
require_command zstd
require_command make
require_command gcc

# Build the pinned experimental access engine for new milestone media.
# Keep rc.5 immutable; the engine is disabled by default in the new candidate.
if [ -x "${ROOT_DIR}/appliance/build-bras-engine.sh" ]; then
    "${ROOT_DIR}/appliance/build-bras-engine.sh"
fi

# Compile the privileged helper from its reviewed source for every build.
gcc -static -O2 -Wall -Wextra -Werror -o "${OVERLAY_DIR}/usr/sbin/nur-mgmt-elev" "${OVERLAY_DIR}/usr/sbin/nur-mgmt-elev.c"
chmod 0755 "${OVERLAY_DIR}/usr/sbin/nur-mgmt-elev"

if [ -n "${NUR_OVERLAY_ROUTER}" ] || [ -d "${AGENT_DIR}" ]; then
    require_command php
fi

if [ -d "${AGENT_DIR}" ]; then
    require_command go
fi

mkdir -p "${ROOT_DIR}/downloads" "${WORK_DIR}" "${OUTPUT_DIR}"

if [ -d "${AGENT_DIR}" ]; then
    mkdir -p "${AGENT_DIR}/bin" "${OVERLAY_DIR}/usr/sbin"
    echo "Building local nur-agent and nur-license-verify binaries for appliance overlay..."
    (
        cd "${AGENT_DIR}"
        GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build \
            -o "${AGENT_DIR}/bin/nur-agent" \
            "./cmd/nur-agent"
        GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build \
            -o "${AGENT_DIR}/bin/nur-license-verify" \
            "./cmd/nur-license-verify"
    )
    (cd "${AGENT_DIR}" && GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o bin/nur-brasctl ./cmd/nur-brasctl)
    (cd "${AGENT_DIR}" && GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o bin/nur-accounting ./cmd/nur-accounting)
    install -m755 "${AGENT_DIR}/bin/nur-accounting" "${OVERLAY_DIR}/usr/sbin/nur-accounting"
    install -m 0755 "${AGENT_DIR}/bin/nur-brasctl" "${OVERLAY_DIR}/usr/sbin/nur-brasctl"
    install -m 0755 "${AGENT_DIR}/bin/nur-agent" "${OVERLAY_DIR}/usr/sbin/nur-agent"
    install -m 0755 "${AGENT_DIR}/bin/nur-license-verify" "${OVERLAY_DIR}/usr/sbin/nur-license-verify"
fi

# Sync the product version stamp into the overlay from VERSION (never ship a stale stamp).
PRODUCT_VERSION="$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION")"
mkdir -p "${OVERLAY_DIR}/etc/nurrouter"
printf '%s\n' "${PRODUCT_VERSION}" > "${OVERLAY_DIR}/etc/nurrouter/product-version"
if [ -f "${ROOT_DIR}/appliance/VERSION" ] || [ -d "${ROOT_DIR}/appliance" ]; then
    printf '%s\n' "${PRODUCT_VERSION}" > "${ROOT_DIR}/appliance/VERSION"
fi

# Lab-only SSH key injection. Release builds must leave authorized_keys absent.
# Always remove any stale key left in the overlay from a previous lab build.
rm -f "${OVERLAY_DIR}/etc/dropbear/authorized_keys"
if [ -n "${NUR_SSH_AUTHORIZED_KEY_FILE}" ]; then
    echo "WARNING: injecting NUR_SSH_AUTHORIZED_KEY_FILE into the overlay (lab builds only)." >&2
    mkdir -p "${OVERLAY_DIR}/etc/dropbear"
    install -m 0600 "${NUR_SSH_AUTHORIZED_KEY_FILE}" "${OVERLAY_DIR}/etc/dropbear/authorized_keys"
fi

if [ ! -f "${ARCHIVE_PATH}" ]; then
    echo "Downloading OpenWrt ImageBuilder ${OPENWRT_VERSION}..."
    curl -fL "${BASE_URL}/${ARCHIVE_NAME}" -o "${ARCHIVE_PATH}"
fi

rm -rf "${WORK_DIR}/openwrt-imagebuilder-"*
tar --use-compress-program=unzstd -xf "${ARCHIVE_PATH}" -C "${WORK_DIR}"

IMAGEBUILDER_DIR="$(find "${WORK_DIR}" -maxdepth 1 -mindepth 1 -type d -name 'openwrt-imagebuilder-*' | head -n 1)"

if [ -z "${IMAGEBUILDER_DIR}" ]; then
    echo "Unable to locate the extracted ImageBuilder directory." >&2
    exit 1
fi

PACKAGE_LIST="$(grep -Ev '^\s*(#|$)' "${ROOT_DIR}/packages.txt" | tr '\n' ' ' | xargs)"

if [ -n "${NUR_OVERLAY_ROUTER}" ]; then
    if [ ! -d "${MANAGEMENT_DIR}" ]; then
        echo "Unable to locate management directory: ${MANAGEMENT_DIR}" >&2
        exit 1
    fi

    echo "Exporting OpenWrt overlay for router ${NUR_OVERLAY_ROUTER} (${NUR_OVERLAY_SOURCE})..."
    (
        cd "${MANAGEMENT_DIR}"
        php artisan nur:export-openwrt-overlay "${NUR_OVERLAY_ROUTER}" \
            --source="${NUR_OVERLAY_SOURCE}" \
            --target="${OVERLAY_DIR}"
    )
fi

echo "Building NurRouterOS image..."
make -C "${IMAGEBUILDER_DIR}" image \
    PROFILE="${OPENWRT_PROFILE}" \
    PACKAGES="${PACKAGE_LIST}" \
    FILES="${OVERLAY_DIR}" \
    ROOTFS_PARTSIZE="${ROOTFS_PARTSIZE}" \
    BIN_DIR="${OUTPUT_DIR}"

echo
echo "Building bootable appliance images (raw, gzip payload, qcow2)..."
"${ROOT_DIR}/appliance/make-images.sh"

echo
echo "Building the hybrid BIOS/UEFI installer ISO..."
"${ROOT_DIR}/appliance/make-installer-iso.sh"

echo
echo "NurRouterOS build completed."
echo "Artifacts are available in: ${OUTPUT_DIR}"
echo "Installer ISO is available in: ${OUTPUT_DIR}/appliance/iso"
