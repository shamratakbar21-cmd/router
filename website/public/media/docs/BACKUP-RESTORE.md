# Configuration and management backup/recovery

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


## Appliance configuration

Use the authenticated Files & Backups workflow on the correct pinned appliance. Export a configuration backup, keep its key/secret handling policy with the owner, preview before restore, inspect the staged diff and expected revision, apply with Safe Mode, and verify observed runtime before confirming. A preview is not an apply. Recovery paths must remain available when licensing blocks new mutations.

Backups can contain network credentials and customer configuration; never upload them into public website storage or a public Git repository. A configuration archive is not a full firmware/disk backup and does not establish cross-version downgrade compatibility.

## WinControl server

The reviewed backup helper captures a consistent SQLite backup, code, storage, selected nginx site and APP_KEY fingerprint into a root-only directory. Preserve the original `.env` and APP_KEY separately in private backup storage. Never generate a replacement APP_KEY during restoration; encrypted enrollment/configuration data depends on it. Restore code/database/storage/site as one compatible set while workers are quiesced. Verify checksums, health, queue/timers, authentication and preserved inventory before reopening mutations.

The provided helper retains backups for 14 days. Back up off-host using the operator's established protected storage; do not assume an on-host copy survives disk loss. Do not expose backups/logs through nginx. Safe Mode configuration rollback is independent of firmware recovery; automatic firmware A/B rollback is unsupported.
