# Controlled-pilot handover — NurRouterOS 0.4.0-rc.3

**Status: PARTIALLY UNBLOCKED on disposable lab; ISO rebuild still required before fresh-install pilot claims.**  
Not bare-metal qualified. Not production-ready. Version **not** bumped solely for this pass.

## Exact artifacts (published ISO — pre-tree-fixes)

| Artifact | Path | SHA-256 | Size |
| --- | --- | --- | --- |
| Installer ISO | `output/24.10.0/appliance/iso/nurrouteros-0.4.0-rc.3-installer.iso` | `708ad4630b06c1fbe39f07c46397708b06b23e88804b84125f21ec9b8c3ca096` | 139429888 |
| Manifest | `evidence/0.4.0-rc/integrated-rc3/FINAL-ARTIFACT-MANIFEST.json` | (see file) | |
| BIOS/UEFI RAW/QCOW2 + payloads | `output/24.10.0/appliance/` + `payload/SHA256SUMS` | listed in manifest | |

**Tree deltas since that ISO** (must rebuild before claiming on fresh installs): `nur-mgmt` passwd home/shell repair, `nur-mgmt-elev` setuid helper, `nur-mgmt-ssh` SCP/allowlist + elev, `nur-runtimectl` shaping observe, `nur-update` + update public-key placeholder, bridge/VLAN/SNAT UCI export in WinControl, `sudo` package list entry.

Source tree: **dirty / no git commits**.

## Verified this pass (disposable QEMU only)

| Item | Evidence |
| --- | --- |
| Host WinControl SSH apply (`nur-mgmt`) | Live apply revision 19; Safe Mode confirm |
| PPPoE client up | `wan_pppoe` / `pppoe-wan_pppoe` / 203.0.113.x |
| Measured HTB shaping | Upload HTB class rate 128000 B/s (=1024 kbit); ingress qdisc present — `evidence/0.4.0-rc/pppoe-shaping-measured/` |
| Bridge/VLAN/SNAT UCI export | Unit test `OpenWrtRuntimeConfigRendererTest` PASS |
| WinControl UI connected/applied | `evidence/0.4.0-rc/wincontrol-screenshots/` |

## External WinControl deployment

Package: `management/deploy/ubuntu/`

- `install.sh` — Ubuntu 22.04/24.04; PHP 8.3-FPM + nginx on dedicated ports (default **8080/8443**); Node ≥20; Composer `--no-dev`; queue + scheduler + Safe Mode timers; logrotate; backup/upgrade/rollback scripts; first admin via `nur:create-admin`
- **Does not** use `php artisan serve` for production-like deploy (lab host currently uses `:8081` artisan serve for disposable evidence)
- **Does not** bind host production :80/:443

Docs: `docs/DEPLOYMENT.md`

## Feature honesty

See `docs/FEATURE-GAP-MATRIX.md`. RADIUS/BRAS intentionally deferred. OTA: designed (`docs/APPLIANCE-UPDATES.md` + `nur-update`); **no A/B automatic rollback**; acceptance suite not executed.

## Soak test

Harness: `lab/soak-8h.sh`. Do not invent elapsed time — check `lab/soak-8h.sh status`.

## Unresolved blockers

1. **Rebuild ISO/images** incorporating tree fixes; re-hash and replace artifact table.
2. **LXD WinControl → appliance SSH** still topology-blocked for remote deploy instance (host WinControl path works).
3. **OTA VM suite** (valid/invalid/corrupt/incompatible/interrupted) not executed.
4. **VLAN/bridge Safe Mode live apply** not separately evidenced (export only unit-tested).
5. **UEFI installer/recovery suite** stale; bare-metal unqualified.
6. **en/bn localization, search/sort/pagination** missing.
7. No clean git revision.

## Exact next operator action

1. Rebuild appliance images/ISO from current tree (`./build.sh` in `nurrouteros/`) **without** bumping `VERSION`.
2. Record new SHA-256 in this handover + `evidence/0.4.0-rc/`.
3. Re-enroll `nur-mgmt` on a **fresh** disposable install and re-run PPPoE shaping + Safe Mode confirm against that candidate.
4. Place pilot WinControl on the management L2/L3 path (not NAT-asymmetric LXD) before claiming remote apply.
5. Do **not** migrate production subscribers or bare-metal without explicit authorization.
