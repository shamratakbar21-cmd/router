# Supported feature matrix (0.4.0 RC)

| Feature | Entitlement code | Enforced | Notes |
| --- | --- | --- | --- |
| Routing / interfaces / addresses / routes | `routing` | Yes (desired-state + apply) | Core |
| Firewall / NAT | `firewall` | Yes | |
| DHCP servers + lease observe | `dhcp` | Yes | |
| DNS forwarders | `dns` | Yes | |
| PPPoE clients | `pppoe` | Yes | OpenWrt PPPoE **clients** (WAN), not BRAS |
| PPPoE HTB shaping | `shaping` | Yes | Requires `tc-full` / `kmod-sched` / `kmod-ifb` |
| PPPoE session admission limit | `max_pppoe_sessions` | Yes | Governs **new** profile admission; 0 = unlimited; established sessions preserved on restrict |
| Max managed routers | `max_routers` | Partial | Soft coordination with activation seats; 0 = unlimited |
| HA export | `ha_export` | **No** | Not implemented — not advertised |
| Priority support | `priority_support` | **No** | Non-runtime — not advertised |

## License states vs mutations

See `LICENSING.md` matrix. Expiry/revoke/unverified block entitlement-gated WinControl mutations; never tear down forwarding, firewall, recovery, or Safe Mode.

## Measured capacity

Do not claim 1,000 subscribers without evidence. Report only measured lab results for the tested artifact.
