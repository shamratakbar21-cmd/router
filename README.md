# NUR RouterOS

## 0.4.0-rc.4 — controlled-pilot candidate

QEMU/KVM pilot release. **Not production-ready or bare-metal qualified.**

The reviewed source is browsable directly in this repository under `agent/`, `appliance/`, `files/`, `management/`, and `docs/`. The unchanged `nurrouteros-0.4.0-rc.4-source.zip` remains available in the rc.4 release. The 211 expanded files match the archive manifest hashes and executable modes; this expansion does not rebuild or replace the installer.

The archive derives from original source commit `53385154ecfb5e2c13adba4ca03cf6c12a2ee823`, with compiled executables and a generated lab overlay manifest excluded. Nine separately identified Go agent source/service files were captured from the existing working tree. They were untracked at the original commit. See `SOURCE-PROVENANCE.json` inside the archive for every file's origin, hash and mode.

## Source availability and licensing

The owner has authorized public source viewing **without granting a new source license** for original NUR code. Public availability is not an MIT, Apache or other open-source license grant. Existing third-party licenses and notices remain applicable; see `THIRD-PARTY-NOTICES.txt`. `LICENSING.md` describes product activation and enforcement, not a source license.

## Artifact provenance

The existing distributed installer is available from https://nurrouter.com/releases/0.4.0-rc.4/nurrouteros-0.4.0-rc.4-installer.iso

Size: 143470592 bytes. SHA-256: `3c8d9e70cfbfab1d4e6e7d3fcc1e176d1d0957b4ea817f00e119ae7feb000605`.

The server artifact hash was checked against the existing release notes and checksum file. **An exact reproducible source-to-ISO match has not been established.** The source snapshot omits built executables, and the added Go source was not tracked in the original commit. No installer was rebuilt or replaced for publication. The snapshot is not claimed to be a complete corresponding-source compliance bundle for all upstream packages in the ISO.

Build entry points: `build.sh`, `appliance/make-images.sh`, `appliance/make-installer-iso.sh`. These require external toolchains, OpenWrt 24.10.0 ImageBuilder and dependencies. Rebuildability of this sanitized snapshot was not tested during publication. Preserve executable modes when extracting. Deployment and recovery instructions are under `docs/`.

No evidence/, lab/, output/, downloads/, dependency vendor directories, production credentials, usable private keys or generated executables are included. Dummy key strings in unit tests are nonfunctional fixtures. Current server working changes were preserved.
