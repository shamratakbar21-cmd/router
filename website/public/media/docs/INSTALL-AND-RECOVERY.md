# Install and recovery — dev.14

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


## Scope

OpenWrt 24.10.0 x86/64 development media. BIOS and UEFI **ext4 ISO installation** passed on disposable VM disks. BIOS live ARP/recovery and UEFI boot/pinned management were exercised. Squashfs payloads in the ISO are built, not newly live qualified. Bare-metal support and physical USB payload discovery remain unqualified/unresolved. No source disk, SSD or RAID change is performed by downloading these files.

## Before boot

1. Download the versioned ISO and SHA256SUMS from [Downloads](/downloads). Verify `sha256sum -c SHA256SUMS` from the release directory, or compare the selected file with its entry. A complete release checksum file also lists optional files you may not have downloaded.
2. Begin on an isolated disposable VM with a separate blank virtual target disk. Choose BIOS or UEFI explicitly and retain console access. The published qcow2 files are virtual lab disks; gzip payloads are compressed whole-disk images, not filesystem files.
3. On a physical machine, inventory the actual controller, devices and NICs before any installation. The physical payload-discovery failure remains unresolved; [read the USB status](/docs/usb-status). Do not assume raw/DD and extracted-file USB preparation are interchangeable. dev.14 discovery expects ISO media. Raw ISO USB boot reached payload discovery under UEFI in the isolated VM; the BIOS raw-USB VM did not reach GRUB. Extracted FAT layouts and physical USB boot are not qualified. Use an optical ISO device for the qualified BIOS/UEFI VM installation path.

## Installer controls

Run `nur-install --help` from the installer console. The shipped implementation supports `--disk`, `--confirm`, `--firmware bios|uefi`, `--rootfs ext4|squashfs`, and `--dry-run`. Its default payload directory is `/mnt/cdrom/payload`. The installer checks payload hashes, rejects the source disk and requires an explicit target and matching `ERASE-<target-basename>` confirmation before writing. `--dry-run` validates without writing.

Do not copy an example device name into a physical write command. Select the confirmed **disposable virtual disk** and ext4 first. A normal installation overwrites the target disk. No physical write/format command is provided as an automatic step here.

## First boot and management

The image contains no enrolled SSH authorization or preset root password hash. SSH password and root-password authentication are disabled in the factory Dropbear configuration. Retain isolated local console access. First boot generates a unique bootstrap root password and device/host identity; inspect the local enrollment status and rotate/bootstrap credentials there before enrolling your own management public key. Do not expose the default development appliance to an untrusted network. Device identity and SSH host keys must be generated on first boot.

Enroll a separate key using the constrained `nur-mgmt` account and a forced command, and pin the host key from a trusted console. Follow [external WinControl setup](/docs/deploy). Never import a staging database, lab credentials or a known_hosts file from another appliance.

## Recovery

The installer recovery mode is selected with kernel parameter `nur.mode=recovery` and uses `nur-recover`. Its menu provides media/target inspection, configuration recovery and explicit reinstall paths. Inspect first. A reinstall requires a named target and confirmation; it is destructive. Retain a compatible offline configuration backup and the original immutable media.

Configuration Safe Mode verifies and reverts configuration revisions. It is **not automatic firmware A/B rollback**. Firmware downgrade, arbitrary cross-version schema restoration and physical-controller recovery are not qualified. Rehearse recovery on disposable disks before relying on the appliance.
