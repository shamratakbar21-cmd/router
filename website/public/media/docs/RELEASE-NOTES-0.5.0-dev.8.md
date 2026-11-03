# NUR RouterOS 0.5.0-dev.8 — public development candidate

Status: the authorized isolated ten-subscriber VM pilot and candidate-specific eight-hour soak completed PASS within their documented scope. Production readiness and bare-metal qualification remain NOT VERIFIED. Public artifact publication was authorized on 2026-11-03. The dev.8 artifact remains immutable and distinct from dev.6 and rejected dev.7 media.

## Changes

- Integrates a checksummed, fsynced, bounded accounting ledger with the existing Accel-PPP RADIUS engine. Start and Stop are retained until authenticated matching Accounting-Responses; pending interims coalesce. The replay worker survives agent and appliance restart. New admission closes before queue or disk limits while established sessions continue.
- Corrects Stop retention when BRAS configuration is disabled after a session starts. Preserves the original NAS, destination, signing secret, event time and session identity for replay.
- Orders VLAN parent configuration before VLAN creation in the agent's desired-network workflow.
- Adds a filtered, sorted, paginated WinControl BRAS session table with English and Bengali labels. The page was exercised from an isolated nginx/PHP-FPM runtime over HTTPS against real appliance sessions.

## Qualification snapshot

Fresh BIOS and UEFI ISO installs, real RADIUS outage/replay, dropped-response duplicate delivery, forged-response rejection, low-disk admission, Safe Mode rollback, signed license admission, one measured shaping profile, disposable reinstall/restore, and a signed dev.6 → dev.8 upgrade have passing evidence in [the dev.8 qualification](/docs/qualification-dev8). The candidate-specific soak closed with 28,851.838 valid healthy seconds and an independent PASS audit. The later authorized pilot exercised ten synthetic subscribers for 600 seconds at approximately 10 Mbit/s offered and achieved in each direction, with zero measured packet loss and no stop-condition trigger.

The queue provides at-least-once accounting delivery. It does not make a permanent storage failure followed by engine loss lossless, nor does it implement exactly-once billing. Automatic firmware rollback/A-B remains UNSUPPORTED. No physical hardware or production subscriber migration was tested. The observed VM traffic and session numbers are engineering measurements, not supported production capacity.
