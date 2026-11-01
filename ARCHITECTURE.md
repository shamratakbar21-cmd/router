# NurRouterOS Architecture Notes

## Preserved appliance build approach

NurRouterOS continues to use the existing OpenWrt ImageBuilder scaffold in [`build.sh`](file:///var/www/nurrouter.com/nurrouteros/build.sh) and [`packages.txt`](file:///var/www/nurrouter.com/nurrouteros/packages.txt). This milestone does not replace that approach.

The intended runtime split is:

- OpenWrt base image: bootable appliance runtime, networking stack, `firewall4`, `nftables`, FRR, PPP, WireGuard, LuCI, and base operating system services.
- Laravel `management/`: unprivileged control plane for NUR WinControl, revision history, authorization, audit logging, observed state, and Safe Mode workflow.
- Go `agent/`: privileged local executor for namespace and network operations behind a Unix socket boundary.

## Runtime placement in the target image

- PHP/Laravel runs as an unprivileged service account and never receives direct host networking capabilities.
- The Go agent runs as a separate privileged service with a restrictive socket policy and bounded command execution time.
- Persistent management state lives in the Laravel database and remains separate from observed networking state:
  - desired state: `router_nodes.desired_configuration` and `desired_revision_id`
  - applied state: `router_nodes.applied_configuration` and `applied_revision_id`
  - observed state: `observed_states`
- Required networking services remain native to the appliance image:
  - routing and interface control through Linux networking and OpenWrt packages
  - firewall and NAT through `nftables`
  - FRR for dynamic routing
  - future DHCP, DNS, and PPPoE services should stay in the OpenWrt runtime rather than moving into Laravel

## Persistent storage expectations

- Laravel revision, audit, auth, and Safe Mode metadata require writable persistent storage.
- The Unix socket and transient agent runtime files belong under `agent/run/` and are not durable state.
- Appliance configuration that must survive reboot should be derived from the applied configuration state and rendered into the target OpenWrt filesystem at install or reconciliation time.

## Socket and service boundary

- Agent socket path: `/var/www/nurrouter.com/nurrouteros/agent/run/nur-agent.sock`
- Expected socket mode: `0660`
- Expected socket group: `www-data`
- Peer authentication is enforced through Unix peer credentials on each connection.
- Agent operations are bounded by a configurable timeout, currently 10 seconds per privileged command.

Validated unit files are in:

- [`agent/systemd/nur-agent.service`](file:///var/www/nurrouter.com/nurrouteros/agent/systemd/nur-agent.service)
- [`agent/systemd/nur-safe-mode-watchdog.service`](file:///var/www/nurrouter.com/nurrouteros/agent/systemd/nur-safe-mode-watchdog.service)
- [`agent/systemd/nur-safe-mode-watchdog.timer`](file:///var/www/nurrouter.com/nurrouteros/agent/systemd/nur-safe-mode-watchdog.timer)

These units were validated with `systemd-analyze verify` and were not enabled on this website host.

## Where Laravel / NUR WinControl runs

**Laravel does not run inside the appliance.** NUR WinControl is the external
`management/` Laravel application on the operator host (or a separate control
host). The appliance image carries only OpenWrt, procd services, `nur-agent`,
and `nur-runtimectl`. WinControl authenticates to the appliance over SSH and
never receives host networking capabilities on the appliance.

## Live appliance connection (WinControl to a booted router)

WinControl (Laravel) is external and unprivileged. It never reconfigures the host; it talks to a booted NurRouterOS appliance over SSH, and the appliance executes changes locally.

- Appliance side: Dropbear `sshd`, `/usr/sbin/nur-runtimectl` (apply/confirm/expire/observe), `/usr/sbin/nur-runtime-watchdog` (procd service), `/usr/sbin/nur-agent` (privileged local executor).
- Control plane side: [`OpenWrtApplianceClient`](file:///var/www/nurrouter.com/nurrouteros/management/app/Services/OpenWrtApplianceClient.php) drives the transfer and remote command; [`RouterConfigurationManager`](file:///var/www/nurrouter.com/nurrouteros/management/app/Services/RouterConfigurationManager.php) owns every state transition.
- Authentication: an SSH key pair per router with a **required** pinned host key (`StrictHostKeyChecking=yes` + enrolled `UserKnownHostsFile`). Enrollment is out-of-band via `nur:register-appliance-ssh`. Connections without an enrolled host-key pin are rejected (`host_key_not_enrolled`). Preferred appliance identity is `nur-mgmt` with Dropbear `authorized_keys` forced-command `/usr/sbin/nur-mgmt-ssh` (no port/agent/X11 forwarding). WinControl only issues typed `ApplianceSshOperation` values (`observe` / `apply` / `confirm` / `cleanup_overlay`); arbitrary remote shell strings are rejected. Private keys stay encrypted in the management DB and are written to mode-`0600` temp files for each call, then deleted. This path is SSH + `nur-runtimectl`, not an mTLS HTTP API.
- Apply path: export the overlay, copy it with `scp -O` (legacy protocol, because Dropbear has no `sftp-server`), then run `nur-runtimectl apply` over SSH. The returned JSON is the new observed state.
- Idempotency: a revision whose desired state already equals the applied state is observed, not re-applied, and therefore does not arm Safe Mode.

## Safe Mode across two independent watchers

Safe Mode is enforced on both sides so it survives control-plane or browser failure:

- Appliance: `apply` writes a pending deadline file under `/etc/nurrouter/runtime/pending/` and backs up the current `network`/`dhcp`/`firewall` UCI config. If the deadline passes without a `confirm`, the appliance watchdog restores the backup and reloads services on its own.
- Control plane: `nur:safe-mode-watchdog` reconciles the database after an expired window and rolls the router back to the previously applied revision.

## Secret handling: management database vs appliance storage

Secrets are protected differently in the two planes, and neither relies on the other for confidentiality:

- Management database: appliance SSH keys, known hosts, and PPPoE passwords are stored with Laravel `encrypted` casts (keyed by `APP_KEY`). Secret columns are never rendered back into HTML forms; blank submissions preserve the stored value.
- Appliance: the rendered UCI config (`/etc/config/network`, `dhcp`, `firewall`) is written mode `0600`. This is where live PPPoE credentials reside at runtime.
- Exports and artifacts: overlay exports mask secrets (`***redacted***`) by default. Real secrets are included only for a transient live apply, which is deleted locally and `rm -rf`'d on the appliance afterwards. Image builds use the repository `files/` overlay, which contains no router credentials.

## Configuration safety model

- Revisions are staged against a `base_lock_version`.
- A revision is rejected as stale if the current lock version no longer matches the staged base version.
- Apply attempts receive a distinct operation UUID and reconciliation deadline.
- Safe Mode applies the revision, records the previous applied state in revision metadata, and requires explicit confirmation before the deadline.
- The watchdog command `nur:safe-mode-watchdog` independently rolls back expired Safe Mode sessions.

## Atomic operations

The following are treated as atomic from Laravel's point of view because the database state transition is committed together:

- creating a staged revision
- moving a router into `applying`
- marking a revision `applied`, `failed`, or `stale`
- confirming Safe Mode

## Compensating rollback operations

The following require compensating action because they cross the Laravel and agent boundary:

- applying namespace or network changes through the privileged agent
- destroying a namespace when Safe Mode expires and no previous applied revision exists
- rolling back to a previous applied revision after an expired Safe Mode window

For these cases, the database tracks the previous applied configuration so the watchdog can issue a compensating rollback after process interruption, Laravel termination, or a delayed watchdog run.

## Bootable appliance and installer artifacts

[`build.sh`](file:///var/www/nurrouter.com/nurrouteros/build.sh) runs the OpenWrt ImageBuilder and then the two appliance stages:

1. [`appliance/make-images.sh`](file:///var/www/nurrouter.com/nurrouteros/appliance/make-images.sh) turns the ImageBuilder output into, for `bios`/`uefi` x `ext4`/`squashfs`:
   - `nurrouteros-<firmware>-<rootfs>.img` raw disk image
   - `nurrouteros-<firmware>-<rootfs>.img.gz` installer payload
   - `nurrouteros-<firmware>-<rootfs>.qcow2` QCOW2 image
   - plus `payload/SHA256SUMS` and `appliance/manifest.json`
2. [`appliance/make-installer-iso.sh`](file:///var/www/nurrouter.com/nurrouteros/appliance/make-installer-iso.sh) builds one hybrid ISO (`nurrouteros-<version>-installer.iso`) that boots on BIOS (El Torito, no emulation) and UEFI (El Torito EFI image plus a plain `/EFI/BOOT/BOOTX64.EFI` tree). It carries `/boot/vmlinuz`, `/boot/initramfs.cpio.gz`, `/boot/grub/grub.cfg`, `/payload/*.img.gz` and `/payload/SHA256SUMS`.

### Installer safety contract

- No disk is ever selected automatically. The target must be named on the kernel command line (`nur.disk=`) or chosen interactively, and it must then be confirmed a second time with the token `ERASE-<diskname>`.
- The payload is sha256-verified before writing, and the written region is compared against the payload afterwards.
- `nur.mode=recovery` starts the local recovery console instead of the installer.

### Device nodes in the installer initramfs

The OpenWrt x86_64 kernel used here is built without `devtmpfs` and BusyBox without `mdev`, so `/dev` starts out empty. [`appliance/installer/nur-devnodes`](file:///var/www/nurrouter.com/nurrouteros/appliance/installer/nur-devnodes) creates every required node from sysfs major/minor values; it is called by `init`, by `nur-install` (at start, after the partition table changes, and inside the grow loop) and by `nur-recover`.

## Verified bootable-appliance behavior

Verified in an isolated QEMU/KVM lab; no host networking was modified:

- raw BIOS (SeaBIOS) and raw UEFI (OVMF) boot of the published `nurrouteros-{bios,uefi}-ext4.img`
- QCOW2 BIOS and QCOW2 UEFI boot of `nurrouteros-{bios,uefi}-ext4.qcow2`
- installer ISO end-to-end on BIOS and UEFI: explicit disk selection, `ERASE-vda` confirmation, checksum verification, payload write, GPT relocation and rootfs grow (UEFI), installation record, reboot prompt
- boot of the disk installed by the installer on BIOS and UEFI, including `/etc/nurrouter-installer/install-record.json`, `eth0 192.0.2.2`, `eth1 10.10.10.1`, `/etc/init.d/nur-agent status` = running, and `/sys/firmware/efi/fw_platform_size` = 64

### Recovery procedures

Boot the installer ISO and choose **NurRouterOS recovery console**, or add
`nur.mode=recovery` on the kernel command line.

| Action | Writes disk? | Confirmation |
| --- | --- | --- |
| Emergency shell | No | none |
| List disks / installation records | No (read-only mounts) | none |
| Verify installer media integrity | No | none |
| Reset root password | Yes | `ERASE-<diskname>` |
| Install / reinstall | Yes | installer `ERASE-<diskname>` flow |

Corrupt or missing installation records are reported as diagnostics only.
Interrupted installs that left a partial disk should be reinstalled after an
explicit wipe confirmation. After a password reset, boot the appliance and set
a new root password before exposing SSH.

### squashfs growth limitation

ext4 payloads may grow the rootfs to fill the target disk (`nur.grow=yes`).
squashfs payloads keep a read-only rootfs and **refuse** installation when
growth is requested; pass `nur.grow=no` (or `--no-grow`) instead.

### Versioning

- **Product version**: `nurrouteros/VERSION` (also `appliance/installer/VERSION`)
- **OpenWrt base version**: `OPENWRT_VERSION` (for example `24.10.0`)
- Artifact `manifest.json` records both, plus sizes, hashes, packages, and build provenance.

Remaining limitations:

- installed-disk verification uses virtual disks under QEMU/KVM; physical bare-metal installation has not been exercised.
- Distributable images must not ship production credentials; device identity and bootstrap tokens are generated on first boot (`files/etc/uci-defaults/99-nurrouteros`). Lab SSH keys live under `lab/` only.

## Commercial licensing

See [`LICENSING.md`](LICENSING.md) for the implemented control plane (RBAC, hashed activation keys, Ed25519 device-bound envelopes, online/offline activation, safe enforcement) and the practical limits of enforcement on customer-controlled hardware.
