# dev.14 factory cleanup and candidate-10 desktop

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.13 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


## Identities

- Appliance: 0.5.0-dev.14; OpenWrt 24.10.0, x86/64. ISO SHA-256: `ffa1a26d27cf2c75e451bc17e29d0928cbf2c06876eaa6beeb496be6165dd11c`.
- Management: accepted candidate-10-desktop source archive SHA-256: `4307f8986eeafb75f6670e720c524f32eedef800164a9f3831033955f3be92ce`. The deployed runtime matches this accepted source; the sanitized public source archive has a different published hash because agent-only instructions are excluded and test signing keys are ephemeral.
- Reviewed public source commit: `47a666ecfde754aee20a882abf7a921dce69f261`. [Manifest](/releases/0.5.0-dev.14/RELEASE-MANIFEST.json) and [SHA256SUMS](/releases/0.5.0-dev.14/SHA256SUMS) identify all public files.

## Factory cleanup

dev.13 is preserved unchanged as the privately accepted ARP baseline. Its factory network contained a PPPoE lab credential placeholder, not a recoverable secret, in an unintended WAN profile. dev.14 removes that profile and historical public test licensing trust, and updates version stamps. Package versions, forwarding/management binaries, neighbor implementation and installer discovery code are unchanged. No physical USB fix is claimed.

## Executed verification

Historical accepted dev.13 appliance evidence: BIOS/UEFI ext4 fresh ISO installation on disposable VM disks; BIOS real neighbor observation and managed static mutations, reboot persistence, Safe Mode/control-plane outage recovery, restricted policy, backup/concurrency/churn; UEFI installed boot and pinned management smoke.

Accepted desktop evidence: 145 PHP tests passed, one opt-in test skipped (784 assertions); 13 browser groups across 22 core panels; multiple windows, user/router-separated workspaces, keyboard behavior, smaller viewports and actual 125% browser zoom; real ARP edit/apply/observed verification with Safe Mode confirmation. Publication-only ephemeral-key test bootstrap reran the same PHP suite successfully.

The production management host was upgraded through the reviewed procedure, preserving APP_KEY, actual administrator and database. Public-internet checks verified direct application login after owner removal of the hostname's Cloudflare Access application, secure cookies, login throttling and anonymous rejection across 37 protected routes. Inventory, licensing and backup/mutation routes remain authenticated. Public pages/docs/downloads remain separate.

## Scope limits

This is a development appliance, not a stable release. Physical USB payload discovery remains unresolved. Physical USB payload discovery, hardware qualification, commercial activation and firmware A/B rollback remain unresolved/unqualified/unsupported. dev.14 has no factory licensing verification key; commercial trust provisioning is unqualified. No dev.14 soak or subscriber-capacity result is inherited from dev.8 or dev.13. The factory-only change requires fresh-media, enrollment, affected ARP/persistence/Safe Mode and recovery checks; unrelated eight-hour/capacity qualification is not repeated. Squashfs payloads are built, not newly live qualified. Frontend coverage does not replace packet-path qualification of every editor.

Original dev.8 and dev.13 evidence and candidate-10 source/captures remain preserved privately. The modified physical dev.10 USB is not published. Historical [dev.8 qualification](/docs/qualification-dev8) remains version-specific. Public screenshots show isolated lab addresses and real VM kernel neighbor entries, not customer networks or sixty physical devices.

## New dev.14 verification

See [QUALIFICATION.json](/releases/0.5.0-dev.14/QUALIFICATION.json) for executed current-media results. BIOS/UEFI ext4 optical ISO installation and recovery-console boot passed on disposable VM disks. UEFI pinned-management/version/neighbor contract passed. UEFI raw-ISO USB reached the installer disk prompt with payload discovery and no write command. BIOS raw-ISO USB did not reach GRUB and is not qualified; no installer change was made from that result. Extracted FAT media and the actual physical failure remain unqualified/unresolved. All four filesystem variants and installer/ISO payloads passed the publication content scan; live qualification remains ext4-only.
