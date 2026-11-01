# NurRouterOS 0.4.0-rc.4

**Label:** controlled-pilot candidate (QEMU). **Not** production-ready. **Not** bare-metal qualified.

## Why rc.4 exists

Public `0.4.0-rc.3` remains available. rc.4 rebuilds the appliance overlay after post-ISO management fixes that were not in the published rc.3 ISO:

- `nur-mgmt` passwd home/shell repair (`/etc/nurrouter/mgmt`, `/bin/ash`) so Dropbear pubkey auth works
- `nur-mgmt-ssh` apply allowlist hardened (full apply argv; ash-safe character class)
- WinControl `ApplianceSshOperation` no longer shell-quotes validated overlay paths (matches appliance allowlist)

## Final installer ISO

| Field | Value |
| --- | --- |
| File | `output/24.10.0/appliance/iso/nurrouteros-0.4.0-rc.4-installer.iso` |
| Size | 143470592 bytes |
| SHA-256 | `3c8d9e70cfbfab1d4e6e7d3fcc1e176d1d0957b4ea817f00e119ae7feb000605` |

## Honest gaps carried forward

- Bare-metal not qualified; QEMU/KVM evidence only
- OTA update tooling without automatic A/B rollback
- Bridge/VLAN and SNAT/DNAT UCI export present; dedicated live acceptance incomplete on fresh ISO
- Source is provided as a reviewed archive in this repository; it is not an exact reproduction of the distributed installer.

## Source publication

The reviewed source is published as `nurrouteros-0.4.0-rc.4-source.zip` in this repository. Download and extract it to inspect the source tree. This browser-based publication uses an archive; the repository does not contain an expanded source tree.

The archive derives from original source commit `53385154ecfb5e2c13adba4ca03cf6c12a2ee823`, with compiled executables and a generated lab overlay manifest excluded. Nine separately identified Go agent source/service files were captured from the existing working tree. They were untracked at the original commit. See `SOURCE-PROVENANCE.json` inside the archive for every file's origin, hash and mode.

## Source availability and licensing

The owner has authorized public source viewing **without granting a new source license** for original NUR code. Public availability is not an MIT, Apache or other open-source license grant. Existing third-party licenses and notices remain applicable; see `THIRD-PARTY-NOTICES.txt`. `LICENSING.md` describes product activation and enforcement, not a source license.

## Artifact provenance

The existing distributed installer is available from https://nurrouter.com/releases/0.4.0-rc.4/nurrouteros-0.4.0-rc.4-installer.iso

Size: 143470592 bytes. SHA-256: `3c8d9e70cfbfab1d4e6e7d3fcc1e176d1d0957b4ea817f00e119ae7feb000605`.

The server artifact hash was checked against the existing release notes and checksum file. **An exact reproducible source-to-ISO match has not been established.** The source snapshot omits built executables, and the added Go source was not tracked in the original commit. No installer was rebuilt or replaced for publication. The snapshot is not claimed to be a complete corresponding-source compliance bundle for all upstream packages in the ISO.

Build entry points: `build.sh`, `appliance/make-images.sh`, `appliance/make-installer-iso.sh`. These require external toolchains, OpenWrt 24.10.0 ImageBuilder and dependencies. Rebuildability of this sanitized snapshot was not tested during publication. Preserve executable modes when extracting. Deployment and recovery instructions are under `docs/`.

No evidence/, lab/, output/, downloads/, dependency vendor directories, production credentials, usable private keys or generated executables are included. Dummy key strings in unit tests are nonfunctional fixtures. Current server working changes were preserved.

Source archive SHA-256: `dbeaa2821195734cfcc296fae14fdf1fcbec5a689cf84a07cbd831fff5b425d0`.
