# Dell PowerEdge R630 bare-metal test handover — NurRouterOS 0.5.0-dev.8

## Scope and current decision

This is a preparation handover, not installation authorization. Dell R630 hardware support is **UNVERIFIED** and bare-metal acceptance is **NOT RUN**. Do not erase a disk, change controller mode, reboot the R630, alter production networking, or attach subscriber links under this handover.

The existing Ubuntu/KVM disks and all MikroTik VM disks are protected assets. Before installation, the operator must identify a separate empty target by Dell service tag, Linux device path, vendor/model, serial/WWN and byte capacity, and must provide explicit authorization naming that disk. Device enumeration such as `/dev/sda` is not an identity and is insufficient by itself.

## Immutable installer identity and transfer

- Server path: `/var/www/nurrouter.com/nurrouteros/output/24.10.0/0.5.0-dev.8/appliance/iso/nurrouteros-0.5.0-dev.8-installer.iso`
- Size: `166983680` bytes
- SHA-256: `fa3e65bf037e385c74d220b8ed5eb006f48b70b7c459cfdf92b5f5b494be62a9`
- Format: hybrid BIOS/UEFI ISO; BIOS El Torito and EFI boot images are present.

The unchanged ISO is also published at `https://nurrouter.com/releases/0.5.0-dev.8/nurrouteros-0.5.0-dev.8-installer.iso`. Download it over HTTPS and verify its SHA-256 before use. Authenticated SSH/SFTP remains available to operators who require a direct transfer from the release host:

```sh
scp -p release-operator@RELEASE_HOST:/var/www/nurrouter.com/nurrouteros/output/24.10.0/0.5.0-dev.8/appliance/iso/nurrouteros-0.5.0-dev.8-installer.iso .
printf '%s  %s\n' \
  'fa3e65bf037e385c74d220b8ed5eb006f48b70b7c459cfdf92b5f5b494be62a9' \
  'nurrouteros-0.5.0-dev.8-installer.iso' | sha256sum -c -
```

The receiving operator must verify the SHA-256 again after copying to the iDRAC virtual-media workstation or USB staging host. A checksum transmitted through the same unverified channel is not an independent trust anchor; compare it with this reviewed handover.

## Read-only R630 inventory — run before any installer boot

Run from the existing Ubuntu/KVM installation. It only queries system state and does not select or write a disk:

```sh
cd /path/to/nurrouteros
sudo python3 lab/target-inventory.py > dell-r630-inventory.json
sha256sum dell-r630-inventory.json > dell-r630-inventory.json.sha256
```

Inventory script SHA-256: `520dda99864eb53cfd088bb49a5b30cb87462706a76dbf058c4012bc89c3d1fa`.

Review the inventory locally before sharing it because serial numbers, WWNs, MAC addresses and network details are sensitive. The review must record:

- Dell service tag, BIOS/iDRAC/PERC firmware, boot mode and Secure Boot state;
- every storage controller PCI ID and active kernel driver;
- every disk path, vendor/model, serial/WWN, exact byte capacity, filesystem, mount point, LVM/RAID membership and business owner;
- every NIC PCI ID, driver/firmware, permanent MAC, negotiated speed and physical port mapping;
- working iDRAC console, virtual-media and power-control access.

Do not pass `--installation-target` yet. After review, it may be used only to record an already approved proposal; it never authorizes or performs a write.

## R630 driver inspection

The inspection used the final dev.8 installed-package manifest and the shipped Linux 6.6.73 kernel configuration. Package presence is not hardware acceptance.

| Hardware family | dev.8 image finding | Status before R630 test |
| --- | --- | --- |
| Dell PERC H330/H730/H730P and other controllers requiring `megaraid_sas` | `CONFIG_MEGARAID_SAS` is disabled; no MegaRAID package/module is in the image | **UNVERIFIED / expected BLOCKED if this is the exposed controller** |
| SAS HBAs requiring `mpt2sas` or `mpt3sas` | Both kernel options are disabled; no module is shipped | **UNVERIFIED / expected BLOCKED** |
| SATA in AHCI mode | `CONFIG_ATA`, `CONFIG_BLK_DEV_SD` and `CONFIG_SATA_AHCI` are built in | **UNVERIFIED until the exact R630 controller/backplane exposes a disk** |
| NVMe | `CONFIG_BLK_DEV_NVME` is built in | **UNVERIFIED on this R630** |
| Broadcom NetXtreme II using `bnx2` | `kmod-bnx2` and `bnx2-firmware` are installed and present in installer initramfs | **UNVERIFIED** |
| Broadcom BCM5720-class NIC using `tg3` | `kmod-tg3` is installed and present in installer initramfs | **UNVERIFIED** |
| Intel I350-class NIC using `igb` | `kmod-igb` is installed and present in installer initramfs | **UNVERIFIED** |
| Intel X520/X540-class NIC using `ixgbe` | `kmod-ixgbe` is installed and present in installer initramfs | **UNVERIFIED** |
| Intel e1000/e1000e/igc and Realtek r8169 | Corresponding installed packages are listed | **UNVERIFIED** |
| Broadcom `bnx2x`, Broadcom `bnxt`, Intel `i40e`, Mellanox or QLogic adapters | Not present in the final installed-package manifest/initramfs inspected for dev.8 | **UNVERIFIED / do not assume support** |

If the read-only inventory reports a PERC logical disk driven by `megaraid_sas`, stop before installation. The immutable dev.8 installer is not a suitable bare-metal candidate for that controller. Adding a driver would require a new immutable version and affected qualification; it must not be copied into dev.8 manually.

## USB or iDRAC boot preparation

### USB

On a separate staging machine, identify the removable USB device by model, serial and capacity. The following is destructive to the USB device and must never target an R630 system disk:

```sh
lsblk -d -o NAME,PATH,SIZE,MODEL,SERIAL,TRAN,RM
# Unmount every listed USB partition explicitly, for example:
sudo umount /dev/USB_PARTITION
sudo dd if=nurrouteros-0.5.0-dev.8-installer.iso of=/dev/USB_DEVICE bs=4M status=progress conv=fsync
sudo blockdev --flushbufs /dev/USB_DEVICE
sudo head -c 166983680 /dev/USB_DEVICE | sha256sum
```

The final command must return the dev.8 ISO hash above. Remove the USB safely and label it with version and hash.

### iDRAC virtual media

1. From a trusted administration workstation, connect to the R630's HTTPS iDRAC endpoint and verify its TLS identity.
2. Open the virtual console, attach the verified ISO as read-only virtual CD/DVD and select a one-time virtual optical boot. Do not change persistent storage-controller settings.
3. Prefer the machine's existing boot mode for the first observation. UEFI and BIOS passed QEMU tests, but R630 firmware and Secure Boot behavior remain unverified.
4. First choose **dry-run install** or **recovery**, list disks read-only, and capture console photographs/logs. Do not select interactive install.
5. Detach production/subscriber links. Use only a dedicated management/test port after its physical mapping is verified.

## Mandatory target-disk gate

Before any destructive menu or `nur-install` command, record a table containing:

| Field | Required value |
| --- | --- |
| Dell service tag | exact value |
| Proposed Linux path | `/dev/...`, observational only |
| Controller and logical-disk ID | exact PERC/HBA/NVMe identity |
| Vendor/model | exact value |
| Serial or WWN | exact value; mandatory |
| Capacity | exact bytes and human-readable capacity |
| Existing partitions/filesystems/mounts | complete list |
| Asset owner/data disposition | confirms the disk is empty/disposable |
| Protected disks | Ubuntu/KVM and MikroTik VM disk identities |

Required authorization wording:

> I authorize erasure of only `[device path]`, model `[model]`, serial/WWN `[serial]`, capacity `[bytes]`, on Dell R630 service tag `[tag]`, for the isolated NurRouterOS dev.8 bare-metal test. The identified Ubuntu/KVM and MikroTik VM disks must not be written.

Approval must name the resolved disk identity, not merely `/dev/sdX`. If any field differs at the installer console, authorization is void and the operator must stop.

## First-boot acceptance checklist

Record each item as PASS, FAIL, BLOCKED or NOT RUN:

1. Installer and installed system detect only the expected controller and disks; verify model, serial/WWN and capacity again.
2. Installed disk boots in the selected BIOS/UEFI mode; record boot ID, device ID and service health.
3. Enumerate NIC PCI IDs, Linux names, drivers and firmware. Map each physical port with one cable at a time and record negotiated speed/duplex using `ethtool`.
4. Keep subscriber and production uplinks disconnected. Establish management only on the dedicated test network, enroll `nur-mgmt`, pin the SSH host key and verify password SSH remains disabled.
5. Reboot once through the independent iDRAC console. Verify disk/NIC enumeration, management, services and configuration persistence.
6. With isolated endpoints, test routed forwarding, TCP/UDP in both directions, packet loss/latency, firewall/NAT as applicable, and CPU impact. Do not extrapolate VM measurements.
7. Test configuration Safe Mode success and rollback paths without depending on Laravel availability.
8. Boot the unchanged installer ISO into recovery, verify media integrity, list the installed disk read-only and confirm the manual reinstall/restore path. Automatic firmware A/B rollback remains unsupported.

## WinControl placement

Laravel WinControl does **not** run on the R630 appliance. It remains on a separate operator-managed Ubuntu host or management VM running nginx, PHP-FPM, workers and HTTPS. That host must reach only the dedicated appliance management interface over explicitly routed management connectivity. Use the `nur-mgmt` account, a dedicated management key and a pinned SSH host key. Appliance-local Safe Mode and installer recovery remain functional when WinControl is unavailable.

## Status at handover

- dev.8 appliance and completed VM-pilot evidence: preserved unchanged.
- ISO transfer: public immutable HTTPS path and authenticated SSH/SFTP method available; downloaded HTTPS bytes verified against the hash above.
- Dell R630 inventory: NOT RUN.
- Exact empty target disk: NOT IDENTIFIED.
- Disk-erasure authorization: NOT GRANTED.
- Physical boot/install/reboot/network changes: NOT RUN.
- Bare-metal qualification: **BLOCKED** pending inventory, driver compatibility, exact target-disk authorization and executed hardware acceptance.
