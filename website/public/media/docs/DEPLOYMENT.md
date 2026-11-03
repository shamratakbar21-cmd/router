# External WinControl — candidate-10 desktop

## বাংলা সারাংশ

অ্যাপ্লায়েন্স dev.14 কেবল বিচ্ছিন্ন পরীক্ষার জন্য। WinControl candidate-10 সার্ভারে production কনফিগারেশনে চালু থাকলেও তা রাউটার ইমেজের production যোগ্যতা বোঝায় না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন এখনো অসম্পূর্ণ। ইনস্টল করলে নির্বাচিত ডিস্কের তথ্য মুছে যায়; ডিভাইসের পরিচয় অনুমান করবেন না। নিচের কারিগরি কমান্ড ও পরিভাষা ইংরেজিতে দেওয়া আছে।


## Architecture

WinControl runs on a separate Laravel management server, not inside the router image. The deployed project endpoint is https://admin.nurrouter.com/login . Application authentication, authorization, CSRF protection and login throttling protect inventory, router changes, licensing and backups. Public marketing/docs/downloads remain on https://nurrouter.com . No public account-registration or anonymous administration is offered.

## Source and requirements

Use the reviewed `nur-wincontrol-candidate-10-desktop-source.tar.gz` and verify its checksum. It contains the maintained runtime and a publication-only test harness that generates ephemeral signing keys. See [source provenance](/docs/source). Requirements are PHP 8.3+, the locked Composer packages, Node 20.19+ or 22.12+, npm 10+, nginx/PHP-FPM, systemd and SQLite tooling for the provided backup helper. Confirm installed versions against composer.lock/package-lock.json; do not update dependencies as a deployment shortcut.

Create required writable storage/cache directories before running Composer scripts or tests. Install runtime dependencies with `composer install --no-dev --optimize-autoloader --no-interaction`; build assets with the locked `npm ci --ignore-scripts` and `npm run build`. For tests, install dev dependencies into an isolated source copy, create `storage/framework/{views,cache,sessions}`, and run `php artisan test --compact` against the configured in-memory test database. The destructive-migration guard protects real application data.

## Production configuration

Use `APP_ENV=production`, `APP_DEBUG=false`, the real HTTPS APP_URL, secure/HttpOnly/lax session cookies, database sessions/queue and writable paths restricted to the service account. Retain the destination APP_KEY and actual database. Never copy `.env`, database files, account credentials, lab routers, test licenses or staging SSH keys from another installation. Generate a unique APP_KEY only for a genuinely new installation.

Use the reviewed scripts under `deploy/ubuntu/`. nginx must serve only `public/`, route PHP through FPM and deny hidden/private files and directory listings. Trust forwarding headers only from explicitly configured trusted proxies. Run the supervised queue worker, scheduler timer, independent Safe Mode timer, and scheduled `nur:reconcile-operations`. Protect/rotate logs and keep private consistent backups with retention. `/up` should reveal no inventory, configuration or environment values.

## Bootstrap and enrollment

For a genuinely new deployment, inspect `php artisan nur:create-admin --help`, create a one-time administrator and rotate the generated bootstrap password on first login. On an existing installation, preserve the actual administrator; do not reset it during upgrade without owner authorization. No shared password is supplied here.

Use a unique SSH key pair, copy only its public key to the appliance console, and constrain the enrollment to `command="/usr/sbin/nur-mgmt-ssh",no-port-forwarding,no-agent-forwarding,no-X11-forwarding,no-pty`. Use the `nur-mgmt` identity and independently verify the host-key pin. Inspect `php artisan nur:register-appliance-ssh --help` for this version's registration options. No router is automatically enrolled or configured by visiting the website.

## Upgrade and rollback

Before upgrading, quiesce mutation workers and capture a consistent database plus matching code, storage, selected nginx site and the original environment/APP_KEY. The reviewed `upgrade.sh` installs an isolated source tree and records the pre-upgrade backup; `rollback.sh` restores code/database/storage/site together and refuses a mismatched APP_KEY. Use a root-only backup location. Do not restore unrelated services or another installation's secrets. Rehearse this on staging first. Production deployment configuration does not qualify router firmware for production.
