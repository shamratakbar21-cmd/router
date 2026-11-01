# NUR WinControl external deployment (Ubuntu)

Laravel **does not run on the appliance**. Deploy `nurrouteros/management` on an operator host that can reach appliance management interfaces.

**Do not use `php artisan serve` for production or pilot.** Use the Ubuntu package under `management/deploy/ubuntu/`.

## Supported target

- Clean Ubuntu 22.04 or 24.04 (VM or LXD instance)
- PHP 8.3 + extensions: `cli`, `fpm`, `sqlite3` (or MySQL), `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl`, `tokenizer`, `openssl`
- nginx, Composer, Node.js (asset build), OpenSSH client
- Persistent disk for `/opt/nur-wincontrol` and backups under `/var/backups/nur-wincontrol`

## Install (on the target Ubuntu system)

```bash
# From a copy of nurrouteros/management on the target:
sudo NONINTERACTIVE=0 \
  DOMAIN=wincontrol.example.invalid \
  LISTEN_HTTP_PORT=8080 \
  LISTEN_HTTPS_PORT=8443 \
  ./deploy/ubuntu/install.sh
```

What the installer does:

1. Verifies PHP extensions and installs missing packages via apt
2. `composer install --no-dev` using the locked `composer.lock`
3. `npm ci` / `npm run build` for frontend assets
4. Generates `APP_KEY` if missing; runs migrations
5. Configures nginx on **dedicated ports** (default 8080/8443) with TLS (operator certs via `TLS_CERT`/`TLS_KEY`, else self-signed lab cert)
6. Enables systemd: `php-fpm`, `nur-wincontrol-queue`, scheduler timer, Safe Mode watchdog timer
7. Installs logrotate + daily backup cron
8. Creates the first administrator (`nur:create-admin`) interactively, or with `--generate` when `NONINTERACTIVE=1`

Health: `sudo /usr/local/sbin/nur-wincontrol-health`  
Backup: `sudo /usr/local/sbin/nur-wincontrol-backup`  
Upgrade: `sudo /usr/local/sbin/nur-wincontrol-upgrade /path/to/new/management`  
Rollback: `sudo /usr/local/sbin/nur-wincontrol-rollback /var/backups/nur-wincontrol/<stamp>` (script at `deploy/ubuntu/scripts/rollback.sh`)

## Trust enrollment (required)

1. On appliance console: `nur-console-enroll status` — record device ID + SSH host-key fingerprint.
2. Generate a WinControl management key pair on the operator host (do not reuse interactive root keys).
3. On appliance: `nur-console-enroll enroll-key /path/to/wincontrol.pub`
4. On WinControl: create router with host, user `nur-mgmt`, private key, and **pinned known_hosts entry**, or:

   `php artisan nur:register-appliance-ssh <namespace> <host> --user=nur-mgmt --private-key-file=... --known-host-file=...`

Connections without an enrolled host-key pin are rejected. **Changed host keys are never accepted silently** — re-verify on console before replacing the pin.

## Licensing keys

On the **management host only**:

```bash
php artisan nur:license-generate-signing-key --write-public-overlay
# Store NUR_LICENSE_SIGNING_PRIVATE_KEY / NUR_LICENSE_SIGNING_KEY_ID in .env
```

Appliances receive public keys only. See `LICENSING.md`.

## First administrator

`nur:create-admin {email} {name}` creates/updates an admin with `must_rotate_password=true`. Rotate on first UI login.

## Backup / restore

- **Management DB + storage:** `/usr/local/sbin/nur-wincontrol-backup` (retain `APP_KEY` offline — required to decrypt SSH material)
- **Router config:** WinControl → Backup (versioned JSON). See `BACKUP-RESTORE.md`
- **Appliance UCI:** Safe Mode / installer recovery remain independent of Laravel uptime

## Transport

WinControl ↔ appliance: **SSH + `nur-runtimectl`** (not mTLS HTTP).
