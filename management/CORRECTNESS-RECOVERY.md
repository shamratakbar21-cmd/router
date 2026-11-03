# WinControl correctness and recovery work

## Scope and source identities

The primary source for these fixes is `/srv/nurrouter/source-20261103`, as confirmed by the operator. It has the desktop UI, BRAS, IPv6, WireGuard, DHCP reservations and diagnostics. This directory has no Git metadata or version file; a source archive and SHA-256 inventory were taken before modification under `/tmp/nur-wincontrol-before-fixes`. It was extracted from `/srv/nurrouter/uploads/NurRouter-management-source.tar.gz` (SHA-256 `35ce0398a972899531ba2223e819ad811b220143ceeff1592ea07fc52fd6d260`): all 137 pre-change application/UI/test/deployment files compared matched that upload, with no divergent code found. File identities, rather than an inferred release number, identify this copy.

`/opt/nur-wincontrol` is an older standalone deployment copy without the desktop assets or BRAS controllers. Its host systemd services point to this directory. Its configured router inventory was empty when inspected. Preliminary changes made there during this task were restored; this task does not deploy the new source into it. The earlier review's WAN-client-only conclusion applies to this older management copy, not to the qualified appliance.

The separate qualification repository is `/var/www/nurrouter.com/nurrouteros`. Its HEAD is `53385154ecfb5e2c13adba4ca03cf6c12a2ee823`, with substantial staged, unstaged and untracked changes; the working version file currently says `0.5.0-dev.10`. HEAD alone therefore does not identify that working tree. Do not overwrite it with this standalone management source.

The preserved dev.8 review source is commit `ecdbd805ae1f51d6b202139b9ef035f86bb88a76`; the appliance build source is `2b1f18c6c0c706c37a94cc57181f4df101f6b882`. BRAS exists in that review's management backend and on the appliance. The preserved evidence records real BRAS sessions, RADIUS accounting and a session page at 411 real sessions. Those results must not be attributed to the older `/opt` application. The qualification runtime is the separate `nur-acct-wincontrol` LXD instance; its `acct-dev8` router uses pinned SSH at loopback port 2270. That runtime and appliance were only inspected, not changed.

The immutable dev.8 ISO SHA-256 is `fa3e65bf037e385c74d220b8ed5eb006f48b70b7c459cfdf92b5f5b494be62a9`; this was rechecked. Appliance source, binaries, media and existing dev.8 evidence were not modified or rebuilt. The new live checks use a disposable QCOW2 overlay on the existing BIOS image, loopback SSH port 2298, and user-mode networking without TAPs or production bridges. Only fixture configuration, enrollment and lab licenses are changed in that disposable guest.

## Dependency setup

The earlier Boost setup in `/opt` added the development requirement `laravel/boost ^2.10`. Composer reported five additions to its lock: Boost 2.10.4, MCP 1.1.2, Roster 1.0.0, composer/semver 3.6.0 and symfony/yaml 7.4.22, with no locked package upgrades/removals. It also installed the previously absent development dependencies. The primary UI source already had these dependencies and versions in its lock; this task ran `composer install`, without changing its Composer declarations or lock. Boost generated local agent guidance/configuration.

The UI source lacked a frontend lock. Its `package.json` was byte-identical to the qualification management package, so its existing `package-lock.json` was retained here and installed with `npm ci --ignore-scripts`. Build verification uses the already installed Node 22.12.0, compatible with Vite 8. Composer validation and platform requirements pass on PHP 8.3.6. The frontend build succeeds; it reports the existing optional `fontaine` fallback-optimization warning. No new optional dependency was added.

## Changes

- PPPoE credentials have retain, replace and clear operations. Omission/blank fields retain the encrypted credential, replacement requires a nonempty value, and profile deletion prunes its credential. Existing plaintext is omitted from model serialization, HTTP projections, editor values and backup previews. Password names containing dots are looked up as literal map keys.
- Staging and revision creation share a transaction. Metadata edits validate licensing before writing and cannot change an appliance connection during an active operation.
- Apply reserves durable ownership with a conditional update before leaving its transaction. Competing applies/rollbacks are rejected. Completion checks both the owner and revision operation ID. Completion does not overwrite a newer desired revision; it rebases the newer revision to the completed applied lock version.
- Unknown transport outcomes retain ownership and staged state. `nur:reconcile-operations`, scheduled every minute, reads operation IDs from the appliance. It finalizes proven applied/confirmed operations and recorded rollbacks. Missing/pruned operation status is not proof of failure: ownership remains blocked for operator investigation. Stale workers cannot finalize newer owners.
- Confirmation reserves its own durable owner before SSH. Interrupted confirmation can be reconciled from the appliance's confirmed/rolled-back status. No long database transaction spans the SSH request.
- Recovery can only restore the snapshot recorded before the current Safe Mode operation. It does not bypass licensing for arbitrary historical or newly supplied configuration. The local-agent path restores that snapshot directly; the appliance path observes its independent watchdog's snapshot restoration and reconciles the database. Confirmation and snapshot recovery do not call ordinary mutation licensing gates. The recovery button does not force an early expiry on immutable media; it reports pending until the appliance restores its local snapshot.
- Backup format 2 encrypts and authenticates the complete secret-bearing document with Laravel Crypt. A SHA-256 checksum is mandatory and any mismatch is rejected. Public backups are untrusted imports, not authenticated documents. Restore uses the normal network edit validator, desktop extension validators, BRAS validation and renderer preflight. Unsupported versions/shapes and plaintext credential fields in public configuration are rejected.
- Restore uses a database transaction and revision history; it no longer writes plaintext secret-bearing rollback files. Transient overlays have protected directories, restrictive file permissions and cleanup on failure.
- UCI output uses a central serializer for dynamic values, handles quotes/backslashes and rejects control characters. DNS search domains become DHCP option 119. Multiple addresses, including management-interface aliases, are represented. Filter input/output/forward chain semantics and NAT action/chain compatibility are explicit. Routes selecting the management physical device map to the logical `management` interface.
- The immutable runtime cannot safely express disabling kernel IPv4 forwarding through its overlay protocol. That request is explicitly rejected with HTTP 422 before staging/upload. Existing unsupported configurations remain viewable with a preview error. Supporting that toggle requires a separately versioned appliance protocol change and acceptance gates; no media change was made here.
- The UI source already calculated observation age correctly in JavaScript. A positive server-side age/stale projection and initial stale indicator now cover initial rendering too. The older `/opt` signed-age bug is not present in this UI implementation.
- Login is throttled by account/IP. Dashboard data requires the same inventory authorization as router management. The schema has one installation-wide inventory, not tenants: finite `max_routers` limits on bound active plans govern that inventory, conservatively taking the smallest finite limit when mixed plans exist. Zero is unlimited. This limit is separate from activation-key seats.
- Installer prerequisites include rsync; Node version preflight matches the Vite requirement. The health check uses the selected PHP version. The newer UI source already had the correct starter redirect expectation. The earlier assertion that the installer’s original two-level default source path was wrong was incorrect: that path is retained and covered by a preflight regression test.
- Deployment snapshots include matching code/vendor/build assets, SQLite data and storage. Rollback checks checksums and APP_KEY fingerprint, then restores the complete set. Upgrade takes the new complete backup before replacing code, quiesces application writers and refreshes helper scripts. No upgrade/rollback was activated on deployed services during this work.

## Backup portability and legacy migration

Secret-bearing backups require the original APP_KEY (or an explicitly configured trusted previous encryption key). A checksum cannot provide authenticity. A secret-free format 2 backup can be imported on another installation after validation, but does not transfer passwords, WireGuard private keys, device identity or licensing. Blank/absent secrets preserve matching existing credentials on the destination. Use explicit credential clear/deletion operations to remove them.

Format 1 imports are disabled by default. Select **Import a trusted version 1 backup**, verify its source out of band, provide its intact checksum and the original key if secrets were included, then preview and restore. Export the result as format 2. A format 1 checksum does not authenticate its public configuration; the explicit legacy option is the migration trust decision. Device identity and license ownership remain excluded. Failed imports/restores leave configuration, secrets and revision pointers unchanged through transaction rollback.

Deployment snapshots contain sensitive storage and recoverable application material. They are root-protected, not automatically encrypted off host. Retain an encrypted off-host copy and the original APP_KEY separately. The provided deployment backup/rollback path requires SQLite and fails explicitly for other database engines. Old-format backups remain explicitly migrated through the import option; encrypted backups encrypted under a lost APP_KEY are unrecoverable.

## Verification

Run from the primary source:

```sh
composer install --no-interaction --prefer-dist
php artisan test --compact
PATH=/opt/nur-tools/node-v22.12.0-linux-x64/bin:$PATH npm ci --ignore-scripts
PATH=/opt/nur-tools/node-v22.12.0-linux-x64/bin:$PATH npm run build
```

The normal suite skips the explicitly opt-in live fixture test. Provision only the disposable fixture described above, then run:

```sh
NUR_RUN_LIVE_FIX_TESTS=1 php artisan test --compact tests/Feature/LiveApplianceRecoveryTest.php
```

Tests cover actual SIGKILL followed by a separate reconciliation process, unknown outcomes, staging during apply, conflicting operations, stale finalizers, credential lifecycle, rollback/confirmation without licensing gates, authenticated backup tampering, wrong-key/legacy imports, failed-restore atomicity, throttling, authorization, inventory limits, UCI safety and deployment restoration. Live checks verify UCI parsing, multiple addresses, dnsmasq search-domain configuration, route application and independent recovery under expired/revoked/invalid-signature licenses. Browser checks use a separate temporary SQLite database and loopback PHP server; they exercise login, desktop credential lifecycle, backup preview and restore without exposing saved secrets.

Final verification: the full suite passed 136 tests with 736 assertions, with the one opt-in live fixture test skipped in that run. The live fixture then passed separately (1 test, 20 assertions). The production build, PHP/shell syntax checks, Composer validation/platform checks, deployment restore tests and real browser workflows passed. Exact results and hashes are recorded in the delivery report alongside this document. Failed live harness attempts are retained separately under the task's private temporary evidence directory; they are not added to or substituted for dev.8 qualification evidence.

## Deployment and rollback procedure (prepared, not executed)

1. Preserve the running application, `.env`/APP_KEY and database. Confirm the selected destination, service user, PHP version, existing appliance enrollment and absence of in-flight operations. Never copy the disposable test database, keys, sessions or browser profile.
2. Review the prepared source bundle and hashes. Test the destination-specific upgrade in an isolated deployment first. This source has no schema additions; its full existing migrations still run normally for a fresh installation.
3. Run the **new source's** `deploy/ubuntu/scripts/upgrade.sh` with `APP_ROOT`, `APP_USER`, `PHP_VERSION` and `BACKUP_ROOT` set for the actual deployment. For example, the host `/opt` services use `APP_USER=nur-wincontrol`; other LXD runtimes must be inspected separately. The script enters maintenance mode, stops application writers, uses the new complete snapshot helper, installs locked dependencies/assets, checks migrations/caches, restores ownership and restarts application services. It does not modify appliance networking or subscriber state.
4. Verify login, authorized inventory, read-only pinned observation, scheduling and `nur:reconcile-operations`. Apply no appliance configuration until separately reviewed. Confirm the reported pre-upgrade backup contains `code.tgz`, `database.sqlite`, `storage.tgz`, metadata and checksums.
5. If validation fails, keep maintenance mode and use the newly installed rollback helper with the exact backup path. Set the same APP_ROOT, APP_USER and PHP_VERSION. It rejects key mismatches, restores previous code/vendor/build assets and matching data/storage, rebuilds caches and restarts services. A legacy backup without code.tgz is not a complete rollback set.

No new release was published. These management changes do not resolve the separately tracked physical installer-media discovery/boot-console issue. Physical installation, subscriber migration, firmware A/B rollback, production networking and production capacity remain outside this task's verification.
