# NurRouterOS 0.4.0-rc.2 — Integrated release candidate

**Label:** Integrated QEMU-oriented release candidate. **Bare-metal not qualified. Not production-ready without pilot evidence.**

## Changes since 0.4.0-rc.1

- Licensing security review closed (Ed25519-only appliance verify, schema v1, state matrix, status refresh, concurrency, compromise ≠ rotation).
- Capability registry + real entitlement gates (features + PPPoE admission limits).
- Versioned configuration backup/restore UI (no device/license identity in backups).
- Installer `VERSION` synced to product version.
- Go `nur-license-verify` included in appliance overlay via `build.sh`.

## Documentation

- `docs/INSTALL-AND-RECOVERY.md`
- `docs/DEPLOYMENT.md` (external Laravel + enrollment + licensing keys)
- `docs/BACKUP-RESTORE.md`
- `docs/FEATURE-MATRIX.md`
- `LICENSING.md`

## Qualification status

See the completion report generated with the build manifest and `evidence/0.4.0-rc/`.
