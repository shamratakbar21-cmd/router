# NUR commercial licensing

This document describes the implemented licensing control plane and appliance enforcement. It does not claim features that are only present in product specifications.

## What is implemented

| Area | Status |
| --- | --- |
| Laravel licensing admin (plans, issue keys, revoke, transfer, offline activate, status refresh) | Implemented |
| Role-based access (`admin` / `license_admin` / `operator`) + audit logs | Implemented |
| Plans: Trial, Standard, Professional, Enterprise with JSON entitlements | Implemented |
| 256-bit activation keys, SHA-256 hashed; plaintext shown once; never audited | Implemented |
| Ed25519 schema-v1 device-bound envelopes; private key server-only | Implemented |
| Online / offline activation; authenticated status refresh envelopes | Implemented |
| WinControl status / activate / renew / refresh / actionable errors | Implemented |
| Appliance `nur-license-check` + `nur-license-verify` (Ed25519 only; **no hash stamp**) | Implemented |
| Device transfer, key vs license revocation, routine rotation vs compromise | Implemented |
| Explicit state/permission matrix (UI, Laravel, appliance) | Implemented |
| Feature tests + appliance harness evidence | See “Executed verification” |

## State / permission matrix

| State | Entitlement-gated mutations | Forwarding / PPPoE / firewall | Local recovery / Safe Mode |
| --- | --- | --- | --- |
| `valid` | allowed | continue | allowed |
| `offline_within_grace` | allowed | continue | allowed |
| `expired_within_grace` | **blocked** | continue | allowed |
| `expired_after_grace` | **blocked** | continue | allowed |
| `revoked` | **blocked** | continue | allowed |
| `invalid_signature` / `unverified` / `unknown_key` / `wrong_device` | **blocked** | continue | allowed |
| `unlicensed` | allowed (bring-up) | continue | allowed |

**Single grace rule:** calendar expiry enters `expired_within_grace` for `offline_grace_days`, then `expired_after_grace`. In **both** expired states, new entitlement-gated changes are blocked; traffic and recovery are never torn down by licensing.

**Offline-within-grace** means the license is still calendar-valid, the last authenticated refresh is within `max_offline_revocation_delay_days`, and the licensing server is unreachable. Mutations remain allowed because the license itself is valid; revocation may be delayed until refresh (see below).

## Cryptography and payload

- Algorithm: Ed25519 (`sodium_crypto_sign_*`).
- Server env: `NUR_LICENSE_SIGNING_PRIVATE_KEY`, `NUR_LICENSE_SIGNING_KEY_ID`.
- Appliances embed **only** public keys in `/etc/nurrouter/license-public-keys.json`.
- Signed schema v1 fields (all authenticated): `schema_version`, `license_uuid`, `activation_uuid`, `plan`, `device_id`, `entitlements`, `issued_at`, `issued_epoch`, `starts_at`, `expires_at`, `expires_epoch`, `offline_grace_days`, `status`, `signing_key_id`, `nonce`.
- Canonical representation: recursive key-sorted JSON (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`), no trailing newline.
- Envelope `key_id` must equal payload `signing_key_id`. Unknown key IDs are rejected.
- **No** `/etc/nurrouter/license.verified` SHA-256 stamp fallback. Missing `nur-license-verify` → `unverified` (mutations blocked; traffic/recovery preserved).

## Activation, renewal, transfer

- Activation keys are bearer secrets (256-bit). Issue once; store only SHA-256 hashes.
- Routes are RBAC-scoped and rate-limited (`throttle:licensing-activation`).
- Activation, renewal, transfer, seat limits, and nonce consumption run in DB transactions with row locks (`lockForUpdate`) so concurrent requests cannot create duplicate active seats.
- Device binding uses server-side `device_id` inventory / first-boot identity. Client-provided hashes are **not** proof of ownership.

## Revocation

| Action | Effect |
| --- | --- |
| Revoke **activation key** | Blocks **new** activations only. Already-issued licenses stay active until license/activation revocation. |
| Revoke **activation** or **license** | Marks server records revoked. Appliance learns via **authenticated status refresh** (new signed envelope with `status=revoked`). |
| Unsigned local “revoked” flag | **Not trusted.** Only signed payload `status` counts. |

**Maximum offline revocation delay:** `NUR_LICENSE_MAX_OFFLINE_REVOCATION_DELAY_DAYS` (default **14**, same as offline grace). A disconnected appliance may continue using a previously verified, still-unexpired license for at most that many days after the last authenticated refresh. After that delay without refresh, entitlement-gated mutations are blocked (`expired_after_grace` semantics) even if calendar expiry has not been reached—because server-side revocation may have occurred and could not be delivered.

## Signing-key rotation vs compromise

| Event | Action |
| --- | --- |
| **Routine rotation** | Publish new key_id + public key to appliances; keep prior public keys in the overlay / `NUR_LICENSE_PREVIOUS_PUBLIC_KEYS` briefly so already-issued envelopes still verify. |
| **Compromise** | List the public key in `NUR_LICENSE_COMPROMISED_PUBLIC_KEYS`, **remove** it from appliance overlays immediately, and re-issue licenses under a new key. Compromised keys must **not** remain trusted merely to preserve old licenses. |

## Device identity and cloning

- `98-nur-device-id` creates `/etc/nurrouter/device-id` once on first boot.
- Two fresh installations must receive different device IDs (see lab harness).
- Copying a disk image **copies** the device ID and license; cloning is therefore not prevented by licensing alone. Treat this as a documented enforcement limit on customer-controlled hardware.

## Practical enforcement limits

Licensing on customer-owned appliances is best-effort deterrence, not DRM. Root access can replace public keys or disable checks. Prefer network continuity over hard locks. Trust boundary: licensing-server private key + WinControl RBAC/audit.

## Operator checklist

1. Set signing private key only on the management/licensing host.
2. Ship matching public keys in the appliance overlay (`jq` + Go `nur-license-verify`; `openssl-util` optional companion).
3. Issue keys from `/licensing`; copy plaintext once.
4. Activate; install signed `license.json`; write epoch to `license-last-refresh` on refresh.
5. On RMA, use device transfer with the original activation key.
6. On signing-key compromise, remove the public key from appliances and re-issue—do not keep it as a “previous” key.

## Executed verification (distinct from tests merely authored)

### Laravel feature suite (executed)

```bash
cd nurrouteros/management
php artisan test --compact tests/Feature/LicensingTest.php
```

Result: **16 passed**.

Regression slice:

```bash
php artisan test --compact tests/Feature/LicensingTest.php \
  tests/Feature/RouterManagementTest.php \
  tests/Feature/OpenWrtApplianceClientTest.php \
  tests/Feature/ApplianceTransportErrorTest.php
```

### Booted appliance cases (executed on live QEMU `192.0.2.2`)

Host commands used to drive the guest (tools installed via `scp -O`, then `/usr/sbin/nur-license-check`):

- valid, tampered, wrong-device, expired_within_grace, expired_after_grace
- revoked (signed refresh envelope), unknown_key, missing verifier, server outage marker
- SHA-256 stamp present with tampered envelope (must still fail)
- Confirmed `ip_forward`, PPPoE iface, nftables line count, and Safe Mode init unchanged after negative cases

Raw log: `/tmp/nur-license-appliance-results.txt`  
Summary: `/tmp/nur-license-appliance-summary.txt` (**12 PASS**, 0 FAIL for appliance matrix + preservation)

### Dual first-boot identity (executed)

```bash
# Simulated independent first boots of 98-nur-device-id with distinct entropy
# plus qcow2 clone note that copied IDs do not prevent cloning
```

Result: two IDs differed (`nur-f2be99…` vs `nur-0abc99…`); clone copies ID (documented limit).
