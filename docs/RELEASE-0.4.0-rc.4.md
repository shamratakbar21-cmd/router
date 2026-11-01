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
- Public source publication still requires a verified project-owned remote
