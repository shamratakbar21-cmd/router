# NurRouterOS 0.4.0-rc.1 — QEMU-verified release candidate

**Label:** QEMU-verified release candidate. **Bare-metal qualification remains unverified.**

## Artifact association

| Item | Value |
|---|---|
| Product version | `0.4.0-rc.1` (see `VERSION`) |
| Prior accepted ISO (preserved) | `nurrouteros-0.3.2-installer.iso` sha256 `86ee623b475a17176f827a05f24d15d5ec1612d8ffee9b8dc30be66f106ced51` |
| Evidence (0.3.2) | `evidence/0.3.2-qemu/` |
| Evidence (0.4.0-rc work) | `evidence/0.4.0-rc/` |
| Source revision | **No clean git commit yet** (`nurrouteros/` has no commits). Tree status captured under evidence manifests. |

## Verified in this RC cycle (QEMU lab)

- Preserved 0.3.2 installer/recovery/live management/routing/PPPoE evidence moved into `evidence/0.3.2-qemu/` with topology + uncommitted-tree note.
- Actionable WinControl UI errors for appliance unreachable (browser + JSON 502); staged config preserved; no success flash on failure.
- SSH transport formalized: required host-key pin, typed `ApplianceSshOperation`, forwarding disabled, redaction, `nur-mgmt` + `nur-mgmt-ssh` forced-command on appliance overlay.
- Watchdog Safe Mode remains local to the appliance (independent of SSH).
- WinControl workspace: movable/resizable persistent windows; interface/address/route/firewall/DHCP/PPPoE/traffic/audit/revision views; Safe Mode countdown; loading/disconnected/stale badges; server-side policy on mutations.
- PPPoE disconnect cleanup + reconnect + LAN traffic after reconnect (1 session); profile rate fields + `nur-pppoe-shape` implemented in source.
- PHPUnit: transport error + client + renderer suites green (full suite 37/38; only pre-existing ExampleTest `/`→302).
- Commercial licensing control plane implemented (was absent), then hardened for release-ready security review: no hash-stamp fallback; schema-v1 signed payloads; explicit state matrix; authenticated status refresh; key vs license revocation; activation rate limits + transactional seat/nonce locks; compromise ≠ rotation; Go `nur-license-verify` on appliance. Feature tests (16) and booted QEMU appliance cases executed — see [`LICENSING.md`](../LICENSING.md) and `evidence/0.4.0-rc/licensing-security/`.

## Outstanding / blocked

| Gap | Status |
|---|---|
| Measured HTB shaping with bidirectional traffic | **Blocked** on current lab image (`tc` missing). Packages `tc-full`/`kmod-sched`/`kmod-ifb` added for next ImageBuilder run. |
| 1000-user capacity | **Not claimed.** Measured sessions = 1 traffic-bearing PPPoE client + 1 LAN client. |
| Dual fresh first-boot identity across two installs | Pending final ImageBuilder/ISO rebuild artifacts. |
| Full installer/recovery re-run against 0.4.0-rc.1 ISO | Pending ISO publish after ImageBuilder. |
| Bare-metal | Unverified |

## External Laravel

See [`DEPLOYMENT.md`](DEPLOYMENT.md).

## Transport

SSH + `nur-runtimectl` only. Not mTLS.
