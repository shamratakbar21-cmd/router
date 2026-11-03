# Appliance updates and immutable candidates

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


Current public appliance: dev.14. Current management desktop: candidate-10. Downloaded development media is not a stable or bare-metal-qualified release. Public launch does not automatically update any appliance or migrate subscribers.

Keep immutable source/archive/image hashes, the installed version, trusted console access and configuration backups. Test a prospective forward update with the exact source/target candidates on disposable disks, including boot, configuration compatibility, management connectivity, rollback/recovery and affected packet paths. Historical dev.6 → dev.8 evidence qualifies only that tested path, not an arbitrary upgrade to or downgrade from dev.14.

No general automated firmware A/B rollback is implemented. Configuration Safe Mode is separate. Reinstallation may erase the selected disk; do not execute it based on a guessed device identity. Physical USB payload discovery remains unresolved. Any actual appliance change must receive a new immutable version and affected BIOS/UEFI/media/recovery tests. Frontend-only management changes do not require rebuilding appliance media.
