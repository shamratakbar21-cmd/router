# Controlled-pilot runbook (placeholders)

## Preconditions

- [ ] Final ISO hash verified: `708ad463…` **or newer rebuild** that includes `nur-mgmt` home-path fix
- [ ] WinControl deployed via `management/deploy/ubuntu/install.sh` on operator Ubuntu host **with L2/L3 reachability to appliance management**
- [ ] Operator TLS certificates installed (`TLS_CERT` / `TLS_KEY`)
- [ ] Licensing signing key generated **once** on WinControl; public key overlay on appliances
- [ ] Out-of-band console access to each appliance

## Bring-up sequence

1. Install appliance from ISO (`docs/INSTALL-AND-RECOVERY.md`)
2. Console: `nur-console-enroll status` → record device_id + host-key fingerprint
3. Console: `nur-console-enroll enroll-key /path/to/wincontrol.pub`
4. WinControl: create admin → create router → paste known_hosts pin (`nur-mgmt` user)
5. Observe/discover → activate license → stage small config → apply with Safe Mode → confirm
6. Export router backup; run `nur-wincontrol-backup` on management host

## Rollback

| Failure | Action |
| --- | --- |
| Bad apply | Wait Safe Mode timeout **or** Confirm/Rollback in UI; staged config preserved on transport errors |
| WinControl bad upgrade | `/usr/local/sbin/nur-wincontrol-rollback /var/backups/nur-wincontrol/<stamp>` |
| Appliance unbootable | Installer `nur.mode=recovery`; list disks (RO); reinstall with `ERASE-<disk>` if interrupted |
| Host-key change | Do **not** auto-accept; console verify; `nur:register-appliance-ssh … --force-replace-host-key` |

## Abort criteria

- Unexpected packet loss > threshold on management path
- Inability to enroll without universal passwords
- License failure that tears down forwarding (must not happen; treat as critical defect)
- Any production interface change on the lab host

## Capacity

Report only measured numbers from soak/evidence. Do not claim 1000 subscribers without measurement.
