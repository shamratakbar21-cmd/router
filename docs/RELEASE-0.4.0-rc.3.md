# NurRouterOS 0.4.0-rc.3

**Label:** controlled-pilot candidate (QEMU). **Not** production-ready. **Not** bare-metal qualified. The installation guide is documentation, not milestone proof.

## Final installer ISO

| Field | Value |
| --- | --- |
| File | `output/24.10.0/appliance/iso/nurrouteros-0.4.0-rc.3-installer.iso` |
| Size | 139429888 bytes |
| SHA-256 | `708ad4630b06c1fbe39f07c46397708b06b23e88804b84125f21ec9b8c3ca096` |
| Manifest | `evidence/0.4.0-rc/integrated-rc3/FINAL-ARTIFACT-MANIFEST.json` |

## What changed versus rc.2

- First-boot hardening: unique root password in local bootstrap file; remote password SSH off; Dropbear bound to `management`; LuCI/uhttpd disabled until enroll; `nur-console-enroll` console path.
- Release builds scrub stale overlay `authorized_keys` unless `NUR_SSH_AUTHORIZED_KEY_FILE` is explicitly set (lab only).
- Product version stamp synced from `VERSION` into the image overlay.
- Installer ISO named from product `VERSION` (not OpenWrt base version).
- Operator guide rewritten to match actual bootstrap/params/persistence/recovery write behavior: `docs/INSTALL-AND-RECOVERY.md`.

## Evidence index

- Acceptance matrix: `evidence/0.4.0-rc/ACCEPTANCE-MATRIX.json`
- Redacted results: `evidence/0.4.0-rc/integrated-rc3/TEST-RESULTS-REDACTED.json`
- First-boot dual: `evidence/0.4.0-rc/first-boot-dual/`
- Installer safety (focused, rc.3): `evidence/0.4.0-rc/installer-safety/`
- Licensing security (prior booted matrix, still applicable): `evidence/0.4.0-rc/licensing-security/`

## Honest gaps

See `pilot_blockers` in the acceptance matrix. Do not treat documentation updates as acceptance.
