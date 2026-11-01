# Installation and recovery (NurRouterOS 0.4.0 RC)

## Scope and honesty

This document describes **actual** installer, recovery, and first-boot behavior from the sources under `appliance/installer/` and `files/etc/uci-defaults/`.

Documentation is **not** acceptance proof. Treat each claim as verified only when an executed evidence file names the exact artifact SHA-256.

- Bare-metal is **not** qualified for this RC unless separately evidenced.
- QEMU/KVM lab installs are the supported verification surface.

## Artifacts

Build with `./build.sh` from `nurrouteros/` (do **not** set `NUR_SSH_AUTHORIZED_KEY_FILE` for release candidates). Outputs under `output/<OpenWrt>/appliance/`:

| Artifact | Path pattern |
| --- | --- |
| Hybrid BIOS/UEFI installer ISO | `iso/nurrouteros-<product>-installer.iso` |
| RAW / QCOW2 (BIOS\|UEFI × ext4\|squashfs) | `*.raw` / `*.qcow2` |
| Manifest + package inventory | `manifest.json`, `payload/SHA256SUMS` |

Never ship signing private keys, management SSH private keys, production credentials, pre-generated device IDs, or embedded `authorized_keys` in distributable images.

## Boot parameters (kernel cmdline)

All of the following are parsed by `appliance/installer/nur-install` and/or selected by GRUB (`appliance/installer/grub.cfg`):

| Parameter | Values | Effect |
| --- | --- | --- |
| `nur.mode` | `install` \| `recovery` | Which program owns the console (`init` + GRUB entries) |
| `nur.interactive` | `0` \| `1` | `0` disables prompts (unattended); default `1` |
| `nur.autoreboot` | `0` \| `1` | Reboot after successful install |
| `nur.dryrun` | `1` | Validate only; **never write** |
| `nur.disk` | `/dev/sdX` etc. | Explicit target disk (required when non-interactive) |
| `nur.confirm` | `ERASE-<diskbasename>` | Unattended confirmation token |
| `nur.firmware` | `auto` \| `bios` \| `uefi` | Firmware layout selection |
| `nur.rootfs` | `ext4` \| `squashfs` | Payload variant to write |
| `nur.grow` | `yes` \| `no` | Grow rootfs after write (**ext4 only**) |

GRUB menu entries set: interactive install; unattended install (`nur.interactive=0 nur.autoreboot=1`); recovery; dry-run install (`nur.dryrun=1`).

CLI equivalents: `--disk`, `--confirm`, `--firmware`, `--rootfs`, `--no-grow`, `--non-interactive`, `--autoreboot`, `--dry-run`.

## ext4 versus squashfs

| Topic | ext4 | squashfs |
| --- | --- | --- |
| Payload | Writable root filesystem image | Compressed read-only root + overlay model as shipped |
| Growth | Supported when `nur.grow=yes` (default). Uses `parted` + `resize2fs` after the payload write. | **Not supported.** Installer refuses `nur.grow=yes` / missing `--no-grow`. Pass `nur.grow=no`. |
| Persistence | Config and state live on the grown rootfs partition | Persistence depends on the squashfs/overlay layout in the payload; growth is not available to enlarge the rootfs image |
| Recovery password reset | Mounts the ext4 rootfs read-write to edit `/etc/shadow` | Reset path requires an ext4-mounted rootfs; if the installed root cannot be mounted as ext4, recovery reports an error and writes nothing |
| Install record | Written to `/etc/nurrouter-installer/install-record.json` on the mounted rootfs after a successful write | Same path when the rootfs can be mounted |

Interrupted writes leave the disk **incomplete**. Treat as failed install: wipe and reinstall with a fresh confirmation. Do not attempt to “repair” a half-written payload in place.

## Install procedure

1. Attach the installer ISO and an empty (or explicitly disposable) target disk.
2. Boot the installer (BIOS El Torito or UEFI `BOOTX64.EFI`).
3. Select install, or boot unattended with `nur.disk` + `nur.confirm=ERASE-<name>`.
4. Confirm the destructive wipe (`ERASE-<diskbasename>`). Wrong token / timeout → **nothing written**.
5. Payload is SHA-256 verified before and after the write.
6. For squashfs: pass `nur.grow=no`. For ext4: growth runs unless `nur.grow=no`.
7. Remove install media and boot the installed disk.

Dry-run (`nur.dryrun=1`) never writes. Source-disk / mounted-target refusal paths also write nothing.

## First-boot identity and credentials (actual behavior)

On first boot, `files/etc/uci-defaults/98-nur-device-id` and `99-nurrouteros` run once:

1. **Unique device ID** written to `/etc/nurrouter/device-id` (`nur-<32 hex>`). Two independently initialized appliances must differ.
2. **Unique SSH host keys** generated under `/etc/dropbear/` when missing.
3. **Unique root password** generated from `/dev/urandom` and stored **only** in `/etc/nurrouter/bootstrap/credentials` (mode `0600`), together with a `bootstrap_token`. There is **no** universal default password and **no** packaged `authorized_keys`.
4. **Remote password SSH is off** (`PasswordAuth` / `RootPasswordAuth` = `off`). Dropbear is bound to the **management** interface.
5. **LuCI/uhttpd is disabled** until operators enroll and deliberately enable it.
6. Local console enrollment helper: `nur-console-enroll`:
   - `status` — device id, host key fingerprint, enrollment state
   - `show-bootstrap` — print local bootstrap credentials (console only)
   - `enroll-key /path/to/wincontrol.pub` — append a forced-command `nur-mgmt` key

Do **not** rely on “set a root password before exposing SSH” as the primary control. Release images deny unauthenticated remote administration by default; enrollment is the intended path for WinControl.

Disk clones copy `device-id` and any local license file. That is **not** a supported ownership transfer — use the licensing transfer workflow.

## Recovery console

Boot installer media with `nur.mode=recovery` (GRUB: “recovery console”).

| Menu action | Writes to disk? |
| --- | --- |
| Emergency shell | No (installer media remains read-only at `/mnt/cdrom`) |
| List disks / installation records | No — mounts candidate rootfs **read-only** |
| Verify installer media integrity | No — reads `/mnt/cdrom/payload` only |
| Reset root password | **Yes** — after `ERASE-<disk>` confirmation: RW-mounts ext4 rootfs, rewrites `/etc/shadow`, keeps `/etc/shadow.nur-backup` |
| Install / reinstall | **Yes** — hands off to `nur-install` (full destructive payload write after confirmation) |
| Reboot / power off | No disk content write beyond sync |

### Interrupted-install detection

Listing disks mounts each candidate partition read-only and inspects `/etc/nurrouter-installer/install-record.json`. Corrupt or incomplete records (missing fields, or record present without a recognizable NurRouterOS root) are reported as diagnostics. Documented operator response: **wipe and reinstall** with a fresh `ERASE-<disk>` confirmation — not in-place repair.

### Password reset and SSH

Password reset is local-console only (no network listener). Clearing the password does **not** enable remote password SSH; Dropbear password authentication remains off until an operator explicitly changes Dropbear policy. Use the local console and `nur-console-enroll` to restore management access.

## Safe Mode

Safe Mode / watchdog restore of the last confirmed UCI backup remains local to the appliance and does not require WinControl connectivity. Licensing failure modes must not block Safe Mode or local recovery (see `LICENSING.md`).

## Safety regressions to re-verify per artifact SHA-256

- Wrong-disk / no-confirm refusal (nothing written)
- Corrupt install-record diagnostics (read-only)
- Interrupted install → wipe + reinstall
- squashfs + `nur.grow=yes` refused
- UEFI and BIOS boot of the installed image
- Two independently initialized appliances: distinct device IDs, distinct SSH host keys, empty management `authorized_keys`, password SSH off
