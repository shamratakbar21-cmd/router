# NurRouterOS 0.5.0-dev.10 boot-console correction

## Scope and decision

This candidate corrects the installer console and media-discovery defect reproduced from immutable 0.5.0-dev.8. It has **not** been installed on physical hardware and does not qualify the Dell R630, its storage controller, NICs, throughput or recovery path. No production network, RAID setting or physical disk was changed.

The immutable dev.8 ISO remains unchanged at SHA-256 `fa3e65bf037e385c74d220b8ed5eb006f48b70b7c459cfdf92b5f5b494be62a9`.

## Root cause

The dev.8 GRUB default supplied both `console=tty1` and `console=ttyS0,115200n8`, with `ttyS0` last. Kernel messages were visible on VGA, but `/dev/console` and PID 1 installer input/output were owned by the serial console. The graphical/iDRAC display therefore appeared stuck while the serial console had already reached the target-disk prompt.

During media discovery, init also tried a generic read-only mount after the explicit ISO9660 attempts. On non-media disks, BusyBox/kernel filesystem probing selected F2FS and emitted the visible `Magic Mismatch` messages. Those diagnostics were a side effect of discovery, not evidence of F2FS corruption and not the reason the prompt was invisible.

## Corrections

- The default installer and recovery entries use `console=tty0` for graphical/iDRAC operation.
- Separate installer and recovery entries use `console=ttyS0,115200n8` for serial operation.
- Dry-run remains available on the graphical console; unattended operation remains explicitly serial.
- Init prints bounded media-discovery progress, checks optical drives first, then removable block devices, and only mounts candidates as ISO9660.
- The build milestone synchronizes product, appliance, installer and packaged `/etc/nurrouter/product-version` identities before building.

## Artifact

| Item | Value |
| --- | --- |
| ISO | `output/24.10.0/0.5.0-dev.10/appliance/iso/nurrouteros-0.5.0-dev.10-installer.iso` |
| Size | `166985728` bytes |
| SHA-256 | `65fc3fda5bb425bfb1aa1ac3bc68dc1afe05da2b88ef24e2588b14770b9e465b` |
| Source baseline | `53385154ecfb5e2c13adba4ca03cf6c12a2ee823` plus the recorded dirty-tree input hashes |

Dev.9 is preserved but **REJECTED**: its installer identified itself as dev.9 while its payload version stamp remained dev.8. It must not be installed or published.

## Executed verification

| Check | Result | Evidence |
| --- | --- | --- |
| dev.8 graphical USB-style reproduction | PASS | `evidence/0.5.0-dev.8/field-console-repro-20261103T120901Z/`; VGA shows F2FS messages while serial reaches the disk prompt |
| dev.10 UEFI removable-USB graphical boot | PASS | `evidence/0.5.0-dev.10/graphical-usb-uefi-20261103T122238Z/graphical.png`; visible media progress and disk prompt |
| dev.10 serial USB media discovery | PASS | `evidence/0.5.0-dev.10/serial-usb-20261103T122324Z/serial.log`; matching dev.10 identities, `/dev/sdb` media and disk prompt |
| F2FS diagnostics after correction | PASS | Zero `F2FS-fs` lines in the serial USB run |
| Target-disk preservation | PASS | Before/after SHA-256 files match; no installer selection or confirmation was sent |
| Packaged product identity | PASS | Rootfs contains `0.5.0-dev.10`; installer manifest reports product and installer `0.5.0-dev.10` |
| BIOS graphical optical boot | PASS on dev.9 boot-path-equivalent media | `evidence/0.5.0-dev.9/graphical-bios-cd-20261103T121711Z/`; dev.9 was later rejected only for payload identity |
| Physical R630 boot/install | NOT RUN | Requires new media transfer and read-only retry; installation still requires exact disk identity and explicit erasure authorization |

## Next operator action

Transfer dev.10 without overwriting dev.8, verify its SHA-256, and retry **read-only boot only** using the default “graphical / iDRAC console” entry. Photograph the visible disk list and run no installation. If a shell is used, collect `lsblk -d -o NAME,MODEL,SERIAL,SIZE,TYPE,TRAN,ROTA` and `lspci -nnk`. Installation remains blocked until an empty target disk is identified by model, serial and capacity and separately authorized for erasure.
