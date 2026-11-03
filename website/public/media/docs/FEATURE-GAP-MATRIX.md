# Known gaps and remaining qualification

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


See the [supported-feature matrix](/features) and [release notes](/docs/release).

| Area | Remaining boundary |
|---|---|
| Physical USB payload discovery | Failure remains unresolved on physical hardware; the exact executed /init fingerprint is still needed. |
| Hardware compatibility | Bare-metal controller, NIC, boot/install and recovery qualification is incomplete. |
| Commercial activation | dev.14 provisions no commercial signing trust; commercial activation and purchase/support services are not qualified. |
| Firmware rollback | No automatic A/B rollback or general cross-version downgrade qualification. |
| Soak and capacity | No new dev.14 eight-hour soak or production-scale qualification; dev.8 results remain historical. |
| Desktop persistence | Browser-local, scoped per user/router; no cross-browser/server synchronization. |
| Visual matching | Compact Winbox reference treatment, not a pixel-identical RouterOS implementation or protocol-compatibility claim. |
| Missing modules | WiFi, Switch, Mesh, MPLS, Dot1X, general Terminal and diagnostics remain unsupported. |
| Other image variants | Squashfs is bundled as built only, not newly live qualified. |
| Source reproduction | No clean-checkout bit-identical rebuild or independent complete corresponding-source compliance audit claimed. |

Menu entries alone do not establish functionality. No subscriber migration or automatic production-router mutation is part of public launch.
