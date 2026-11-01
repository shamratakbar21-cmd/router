# Appliance updates (NurRouterOS)

## Honest status

**Recoverable OTA is designed and partially implemented** (`files/usr/sbin/nur-update`). It is **not** yet a verified acceptance item on a disposable VM suite for this RC until the evidence directory names a candidate SHA-256 and the five negative/positive cases below pass.

There is **no A/B rootfs** on the current BIOS/UEFI layouts. Therefore this project **does not promise automatic rollback** after a failed update. Recovery after a failed or interrupted update is the installer `nur.mode=recovery` / reinstall path already documented in `INSTALL-AND-RECOVERY.md`.

## Partition constraints (inspected)

BIOS ext4 example (`nurrouteros-bios-ext4.qcow2`):

| Partition | Size | Role |
| --- | --- | --- |
| p1 | 16 MiB | Boot (kernel/grub) |
| p2 | 512 MiB | ext4 root (≈405 MiB free after first boot on the stock image) |

Squashfs images keep a read-only root plus overlay; growth is not supported. Prefer **ext4** appliances for in-field updates that rewrite the rootfs via OpenWrt `sysupgrade`.

## Strategy (feasible without layout redesign)

1. **Payload** — OpenWrt sysupgrade-compatible image produced by the existing ImageBuilder pipeline (`*-ext4.img.gz` / squashfs variants as applicable).
2. **Metadata** — JSON sidecar signed with **Ed25519 update keys** stored separately from license-signing keys (`/etc/nurrouter/update-public-keys.json` on the appliance; private key only on the release signer host).
3. **Preflight** — architecture match (`x86_64`), product family match, minimum free space, refuse concurrent `nur-update` / installer locks, refuse older `product-version` unless `--allow-downgrade` is set (audited).
4. **Config preserve** — use `sysupgrade -c` / keep-list for `/etc/nurrouter`, Dropbear host keys, and enrolled `nur-mgmt` authorized_keys. Explicit migrations live under `/etc/nurrouter/migrations/` when a release requires them.
5. **Failure** — if `sysupgrade` does not return success, leave the lock cleared and instruct recovery via installer. Do **not** advertise automatic rollback.

## Keys

| Key | Purpose |
| --- | --- |
| License Ed25519 | Entitlement envelopes only |
| Update Ed25519 | Update metadata + payload digest attestation only |

Never reuse license private keys to sign update metadata.

## Acceptance cases (disposable VMs only)

| Case | Expected |
| --- | --- |
| Valid signature + compatible image | Update applies; device returns with new `product-version`; mgmt SSH still enrolled |
| Invalid signature | Reject; running system unchanged |
| Corrupted payload (digest mismatch) | Reject; running system unchanged |
| Incompatible arch / product | Reject; running system unchanged |
| Interrupted update | Treat as failed install; recover with installer — **no automatic rollback** |

## Operator command (appliance console or allowlisted elev)

```sh
nur-update --metadata /tmp/update.json --image /tmp/image.bin
nur-update --metadata /tmp/update.json --image /tmp/image.bin --allow-downgrade   # audited recovery only
```

WinControl transport for updates is **not** wired in this pass (SSH allowlist remains observe/apply/confirm/cleanup only).
