# Supported feature matrix — dev.14 / candidate-10

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


The [live feature matrix](/features) distinguishes verified behavior from editors, historical tests and unsupported entries.

- **Verified desktop:** independent floating windows; move/resize/minimize/maximize/restore/focus/stack; browser-saved workspaces per authenticated user/router; persistent resizable/reorderable/visible columns; keyboard and small-viewport/zoom behavior; selection, scroll, focus and unsaved editor continuity during refresh.
- **Verified real ARP on dev.14:** IPv4 Linux neighbor observation; managed static add/edit/delete; explicit enable/disable; comments; revisions and audit; reboot persistence; applied runtime verification and Safe Mode rollback. No VRF, bridge-port, hostname or RouterOS flags are invented. Editing remains capability-gated.
- **Implemented core editors/views:** interfaces/addresses/routes, Bridge/VLAN, DHCP/DNS, firewall/NAT, PPPoE/shaping, WireGuard and IPv6 share desktop components. HTTP/editor tests are not packet-path or hardware qualification for every feature.
- **Historical dev.8 lab evidence:** earlier forwarding/BRAS/RADIUS/shaping and soak/capacity evidence belongs to that exact candidate and remains historical. It is not inherited by dev.14.
- **Unsupported:** WiFi, Switch, Mesh, MPLS, Dot1X, general Terminal, appliance diagnostics, firmware A/B rollback. No enabled placeholder action claims these capabilities.
- **Not qualified:** physical hardware/USB discovery and commercial activation. Factory lab signing trust is removed; no commercial trust chain is provisioned.
