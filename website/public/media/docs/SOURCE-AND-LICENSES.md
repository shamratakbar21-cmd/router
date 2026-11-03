# Reviewed source and license notices

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


The verified project-owned repository is https://github.com/shamratakbar21-cmd/router . Current reviewed source is published there without force-pushing, and is also available in the checksum-verified source archives and offline Git bundle below. The reviewed dev.14 / candidate-10 source commit is `868698fdd7ee9d744167cc8816f97d368c2e8fb6`. Source was prepared in a separate publication checkout; qualification repositories and immutable snapshots were not rewritten.

## What the source contains

- Maintained candidate-10 management runtime, selected dev.14 appliance scripts/overlay and Go/C source.
- `PUBLICATION-PROVENANCE.json` records accepted archive identity, file hashes, exclusions and publication-only changes.
- The current test harness generates an ephemeral Ed25519 signing key per test process. Historical public commits/archives retain the already-public nur-lic-test fixture; no history was force-rewritten. That historical fixture is not a commercial signing credential.
- Compiled overlays, runtime environments/databases, enrolled keys, private backups, agent-only instructions and raw private evidence are excluded. The public source archive therefore has its own hash, distinct from the frozen accepted staging archive.

## Existing terms

Public viewing is authorized without a new blanket source license for original NUR code. Public availability is not an MIT/Apache license grant for the whole project. Preserve `THIRD-PARTY-NOTICES.txt`, framework/dependency notices and applicable upstream licenses. Product `LICENSING.md` describes activation behavior, not copyright licensing.

## Upstream components and build inputs

[PACKAGE-SOURCES.json](/releases/0.5.0-dev.14/PACKAGE-SOURCES.json) records installed package versions, source recipe paths, source-date epochs and licenses. [SBOM.cdx.json](/releases/0.5.0-dev.14/SBOM.cdx.json) records the image component inventory. OpenWrt build recipes and source archives are available from https://github.com/openwrt/openwrt/tree/openwrt-24.10 and https://sources.openwrt.org/ . The exact ImageBuilder/SDK release directory is https://downloads.openwrt.org/releases/24.10.0/targets/x86/64/ .

Pinned accel-ppp, OpenSSL, PCRE2 and libucontext upstream archives are included in `source-inputs/`, with checksums matching the engine build input manifest. NUR engine changes are in `appliance/patches/`; preserve the terms applicable to upstream code and derivatives. OpenSSL source contains public upstream test vectors, not an operational NUR signing key. Copy the supplied archives into `downloads/` and obtain the pinned SDK before building.

No clean-checkout bit-identical rebuild or independent complete corresponding-source compliance audit is claimed. Package feeds contain versioned upstream components beyond the base kernel. Operators who redistribute images must preserve notices, satisfy each applicable source obligation and verify exact package/source correspondence; the source index is not represented as a complete compliance certification.

## Factory cleanup and retained baseline

dev.13 remains unchanged and private as the accepted ARP baseline. Publication review identified a lab WAN profile with a redacted password placeholder, not a recoverable secret, plus public fixture licensing trust. dev.14 removes that profile and trust and updates version stamps. Installed packages and engine/neighbor/discovery code are unchanged. Candidate-10 management is unchanged.

## Source archives and offline Git inspection

The source archives on [Downloads](/downloads) contain the current reviewed appliance and management source. [Reviewed-project-source.bundle](/releases/0.5.0-dev.14/reviewed-project-source.bundle) includes the reviewed history and public website source for offline Git inspection. Verify it against SHA256SUMS before cloning. The archive source commit identifies the appliance/management source; the later website publication commit adds the public frontend and guides.
