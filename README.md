# NUR RouterOS and WinControl

Current appliance: **0.5.0-dev.14**, OpenWrt 24.10.0 x86/64, isolated evaluation only. Current management: **candidate-10-desktop**, deployed with production configuration at https://admin.nurrouter.com/ . The management deployment does not qualify the router image for production.

## Supported and verified scope

The desktop provides floating windows, per-user/router browser workspaces, table column preferences, and selection/focus preservation on live refresh. ARP supports actual Linux neighbor observation and managed static CRUD, explicit enable/disable, comments, revisions, persistence, audit, and Safe Mode verification/rollback on a capable appliance. Unsupported panels and actions remain disabled or labelled.

Appliance evidence covers BIOS/UEFI ext4 ISO installation in VMs, BIOS live ARP and recovery workflows, and UEFI boot/pinned-management smoke. Other bundled filesystem variants are built, not newly live qualified. No dev.14 soak/capacity result is inherited from dev.8.

## Limits

Physical USB payload discovery remains unresolved. The modified physical dev.10 USB is not a qualified release and is not published. Bare-metal storage/NIC qualification, commercial activation, automatic firmware A/B rollback, and production subscriber migration are not accepted. The dev.14 factory image removes the baked-in lab PPPoE profile and public fixture licensing trust discovered in dev.13. No commercial trust chain is provisioned. dev.13 is preserved privately; it is not the public download. Production licensing signing credentials are not included.

## Source provenance and licensing

`PUBLICATION-PROVENANCE.json` ties every selected input to the immutable dev.13 baseline, new dev.14 input manifest and candidate-10 snapshot and lists publication-only exclusions. Runtime management source is unchanged. Its test harness now generates ephemeral signing keys instead of embedding the historical public fixture private key. Historical commits/archives remain unchanged and may contain that already-public test fixture.

Public source viewing remains authorized **without a new blanket source license for original NUR code**. Existing third-party terms remain applicable. `LICENSING.md` describes product activation, not copyright licensing. Preserve `THIRD-PARTY-NOTICES.txt`, upstream license files, and the terms applicable to third-party code and derivatives. See `source-inputs/README.md` and the release source index for upstream inputs. Do not treat this repository as a claimed complete corresponding-source compliance bundle or a bit-identical reproduction of the ISO.

## Build and deployment

Appliance entry points: `build.sh`, `appliance/build-bras-engine.sh`, `appliance/make-images.sh`, and `appliance/make-installer-iso.sh`. Generated binaries, private environments, databases, enrolled keys, raw private evidence and dependency vendor trees are excluded. Use `management/deploy/ubuntu/` for external WinControl deployment; preserve the destination APP_KEY and real database. The public guides at https://nurrouter.com/docs cover installation, external management, recovery and release limitations.

Reviewed historical rc.4 source and its archive are retained in repository history. No tag or history is force-rewritten.
