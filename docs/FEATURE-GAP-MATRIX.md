# Product feature gap matrix (inspected 2026-11-01, updated after live PPPoE shaping)

Classification vs original ISP-router scope. **Never advertise missing as supported.**

| Area | Status | Notes |
| --- | --- | --- |
| Interfaces (ether/mgmt/dummy/veth) | implemented-unverified | Schema+UI; UCI now emits bridges/802.1q; live bridge apply pending dedicated acceptance |
| Bridges | implemented-unverified | UCI `config device type bridge` + ports from `master=` exported; Safe Mode apply not yet separately evidenced |
| VLANs / bridge VLANs | implemented-unverified | UCI `8021q` + `bridge-vlan` exported; live verification pending |
| Bonding | missing | Not in schema/UI/export |
| IPv4 addresses/routes | verified | Desired→UCI→apply |
| IPv6 desired/apply | missing | Observe may show IPv6; no desired IPv6 |
| Policy routing / tables | missing | |
| Firewall filter | verified | |
| NAT masquerade | verified | |
| SNAT/DNAT | implemented-unverified | UCI `config nat` / `config redirect` now exported; live nft verify pending |
| Conntrack tuning | missing | |
| DHCP server | verified | |
| DHCP client | missing | |
| DNS forwarders | verified | |
| DNS search domains | implemented-unverified | Stored; written as comments only |
| PPPoE **clients** (WAN) | verified | Live disposable appliance: session up + Safe Mode confirm (`evidence/0.4.0-rc/pppoe-shaping-measured/`) |
| PPPoE BRAS / pools / RADIUS | intentionally deferred | Not OpenWrt client scope; do not advertise |
| Subscriber accounting (RADIUS acct) | intentionally deferred | Same |
| Queues / HTB shaping | verified | HTB upload + ingress download observed on `pppoe-wan_pppoe` (1024 kbps / 2048 kbps targets) |
| WireGuard | intentionally deferred | Packages/LuCI only; no WinControl workflow |
| FRRouting | intentionally deferred | Packages on image; no WinControl control plane |
| Users/roles UI | missing | CLI admin; `is_admin` gate; license roles partial |
| Audit logs | verified | |
| Diagnostics UI | missing | Observe JSON includes `shaping_status`; no dedicated diagnostics workspace |
| Licensing | verified | |
| Fleet / max_routers enforce | implemented-unverified | Soft; not hard-gated on create |
| Backup/restore (config) | verified | |
| Appliance OTA update | implemented-unverified | `nur-update` + `docs/APPLIANCE-UPDATES.md`; **no A/B rollback**; VM suite not executed this pass |
| Installer recovery | verified (partial) | BIOS-focused; UEFI suite stale |
| WinControl windows/layout | verified | localStorage layout |
| Pending vs applied / Safe Mode UI | verified | Live confirm after PPPoE apply |
| Search/sort/pagination | missing | Hard take(N) |
| en/bn localization | missing | |
| Keyboard/zoom | implemented-unverified | Basic HTML forms; not audited |

**Pilot label:** WinControl↔appliance SSH apply works on lab after `nur-mgmt` home/shell + setuid elev + SCP path fixes. Tree changes require **ISO rebuild** before claiming them on fresh installs. Measured PPPoE shaping: **PASS** on disposable QEMU. OTA acceptance suite: **not run**. RADIUS/BRAS: **deferred**.
