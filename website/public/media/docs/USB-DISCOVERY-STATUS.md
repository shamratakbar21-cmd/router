# Physical installer USB discovery remains unresolved

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.13 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


The physical screen reported `installer payload media is not available at /mnt/cdrom` and entered BusyBox. Preserve the original USB, its read-only copies and evidence. No formatting, disk identity guess or RAID change is required for diagnosis.

The actual mounted USB was identified as development-era media with current and saved-original initramfs files. Its boot inputs were hashed and copied privately. Exact kernel/initramfs VM experiments with extracted FAT32 media showed that the shipped modprobe implementation reads module ELF metadata; absent modules.dep/modules.alias did not by itself establish a dependency-loading defect. Unsuppressed module loading, NLS/filesystem checks and explicit read-only FAT mounts passed in the accepted isolated experiments. The saved-original discovery implementation only accepted ISO media; the modified current USB had a VFAT fallback. These findings do not prove which /init the physical failed boot executed or resolve the physical failure.

The modified physical USB is not a qualified immutable candidate and is not distributed here. No speculative image-build fix or new appliance candidate was produced. dev.13 remains unchanged.

## Next physical check — read-only BusyBox commands

```sh
sha256sum /init
uname -r
readlink /sbin/modprobe
cat /proc/filesystems
```

Return this output with the actual error before attributing the failure to drivers, filesystems or module loading. If further inventory is needed, `cat /proc/partitions`, `blkid`, `cat /proc/mounts` and `dmesg | tail -40` are read-only. Do not assume the USB device is /dev/sdb or mount/write a guessed device. Device-node/driver presence, filesystem signature, raw ISO versus extracted FAT layout, payload location, labels/paths and console output must be distinguished from actual evidence.
