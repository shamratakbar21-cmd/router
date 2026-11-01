# Backup, restore, and hardware replacement

## Versioned backup format

WinControl **Backup** downloads `nurrouter-config-backup` JSON (`format_version` 1):

- Desired configuration (no `device_id`, no license ownership)
- Optional encrypted secrets blob (`Crypt` ciphertext) when explicitly requested
- SHA-256 integrity over the document body without the integrity field
- Mode hints for same-router / replacement / clone

## Restore

1. Paste backup JSON → **Preview** (compatibility + integrity + counts).
2. Confirm restore → writes desired state as a new revision (entitlement gates still apply).
3. On failure, previous desired configuration is reinstated from a local rollback snapshot.
4. Operator must **Apply** for appliance changes; Safe Mode still protects confirm/rollback.

## Distinctions

| Scenario | Device identity | License | Config backup |
| --- | --- | --- | --- |
| Same router restore | Unchanged | Unchanged | Restore desired state |
| Replacement hardware | New first-boot `device-id` | Official **device transfer** with activation key | Restore config after transfer |
| Disk clone | **Copied** (unsupported ownership) | Local license file copied | Not a valid transfer |

Cloning disks does not transfer license ownership. Use WinControl licensing transfer.
