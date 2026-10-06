# Deployment helpers

| File | Purpose |
|------|---------|
| `setup-https.bat` | **On the SERVER, as administrator, once.** Whole HTTPS setup, with backups |
| `connect-client.bat` | **On every OTHER PC, as administrator.** Points the name at the server and trusts the certificate |
| `trust-cert.bat` | Trusts the certificate only. `connect-client.bat` calls this; run it alone if the name already resolves |
| `package-server.bat` | **On the DEVELOPMENT PC.** Builds `dist\lms-server-*.zip` with only the files the server needs |
| `make-shortcuts.bat` | **On the SERVER, once.** Puts *Start* / *Stop* icons with the municipal seal on the desktop |
| `lms.ico` | The seal as a Windows icon, used by those shortcuts |
| `make-cert.sh` / `make-cert.bat` | Generate a self-signed TLS cert (`certs/lms.crt`, `certs/lms.key`) |
| `apache-vhost.conf` | Apache VirtualHost (HTTP→HTTPS redirect, LAN-only, TLS) |
| `lms-queue.service` | systemd unit for the queue worker (Linux) |

See `docs/Deployment.md` for the full step-by-step LAN/XAMPP installation and the
Windows Task Scheduler entries for the queue worker and scheduler. Beginners should
follow `docs/RUN_ON_YOUR_PC.md`.

## Installing on the server

**What the server needs** — and nothing else:

| Keep | Why |
|------|-----|
| `app/ bootstrap/ config/ database/ resources/ routes/` | the application |
| `public/` | the only folder Apache serves |
| `storage/` | logs, sessions, uploaded documents — must be writable |
| `vendor/` | PHP libraries (`composer install --no-dev`) |
| `deploy/` | HTTPS setup, certificate, shortcuts, icon |
| `artisan`, `composer.json`, `composer.lock`, `.env.example` | Laravel itself |
| `start.bat`, `stop.bat` | daily use |

**Leave off the server:** `tests/`, `docs/` (except the user manuals),
`.github/`, `.claude/`, `node_modules/`, `package*.json`, `vite.config.js`,
`phpunit.xml`, `test.bat`, `update.bat` (needs Git), `DemoDataSeeder.php`,
and above all **your own `.env`, `deploy/certs/` and `storage/logs/`** — those
belong to the machine that made them. `package-server.bat` does this sorting
for you.

**Steps**

1. On the development PC: `deploy\package-server.bat` → copy the ZIP over.
2. On the server, install XAMPP (PHP 8.3+). Unzip, and move the `lms-server-…`
   folder inside it to `C:\xampp\htdocs\lms`.
3. In phpMyAdmin create the database `lms_alicia` (utf8mb4_unicode_ci).
4. In the project folder, from a terminal:
   ```
   copy .env.example .env
   php artisan key:generate
   ```
   Edit `.env`: database password, and change `SEED_SUPERADMIN_PASSWORD`.
5. ```
   php artisan migrate --force
   php artisan db:seed --force
   php artisan storage:link
   ```
6. Right-click `deploy\setup-https.bat` → **Run as administrator**.
7. `deploy\make-shortcuts.bat` (or `make-shortcuts.bat all`, as administrator,
   for every account on the server).
8. Double-click **LGU Alicia LMS - Start** on the desktop.
9. On each office PC: `deploy\connect-client.bat <server IP>` as administrator.

Then log in as the super admin, change its password, and register the office
PCs under **Admin → Authorized Devices** (see `docs/Deployment.md` §5).

**Updating later:** build a new package, stop the system, and unzip it over the
old folder — the ZIP carries no `.env`, certificate, logs or uploaded files, so
the server's own are kept. Then run `php artisan migrate --force` and start again. Back up
first with `php artisan lms:backup`.

## Why the address is `.lan` and not `.local`

`.local` is reserved for mDNS/Bonjour. iOS and macOS resolve those names through
Bonjour and never send them to the router's DNS, and Windows can route them to
mDNS too — which is what produced a correct-looking hosts file and a browser
still reporting `DNS_PROBE_FINISHED_NXDOMAIN`. A `.local` name therefore cannot
be published to phones at all, whatever you configure.

`.lan` is treated as an ordinary name by every resolver, so both a hosts entry
and a router DNS record work.

`setup-https.bat` and `connect-client.bat` each remove the old `.local` hosts
lines and untrust the old certificate as their first step, so running them is
the whole migration. The hosts file is backed up beside itself first.

## Moving the server to a different network

Another Wi-Fi, another router, or the office itself: the server's IP address
changes, and five things are pinned to the old one.

**On the server**, this is the whole migration:

```
deploy\setup-https.bat        (as administrator)
start.bat
```

It re-detects the address and rebuilds everything from it — the vhost's
`Require ip` subnet, the `ServerAlias`, and the certificate, which is now
reissued when it no longer covers this machine's address rather than only when
it no longer covers the hostname.

**Then on every other PC**, because the certificate was reissued and the old
one is no longer valid anywhere:

```
deploy\connect-client.bat <new server IP>     (as administrator)
```

Skipping this leaves that PC pointing at an address nothing answers on, and
trusting a certificate that no longer exists.

**Check the network profile.** Windows classifies every network it has not been
told about as **Public**, and the firewall rules are scoped to private and
domain networks — so joining a new Wi-Fi silently undoes the firewall step. The
rules stay listed and stop matching. `setup-https.bat` now warns about this, and
`check-lan.bat` shows which adapter is which. Set it to Private in Settings →
Network & Internet → your adapter.

**Ask for a fixed address.** A DHCP reservation on the router, binding the
server to one address, avoids repeating all of the above every time the lease
changes. Worth doing before the LGU rollout rather than after.

**Redo the router DNS record** for phones: `onealicialms.lan` → the new IP.

## Phones and tablets

A phone has no hosts file, so `connect-client.bat` cannot help it. Two things
are needed, and they are independent:

**Name.** Add one DNS record on the office router: `onealicialms.lan` → the
server's IPv4 address. That covers every device on the network at once, phones
included, and is one edit when the server's address changes.

If the router cannot hold a local DNS record, phones can use the address
directly — `https://192.168.254.102`. The server's own IP is now written into
the certificate's subjectAltName by `setup-https.bat`, so that no longer fails
with `NAME_MISMATCH`.

**Certificate.** Copy `certs/lms.crt` to the device (email or a shared folder).
The certificate now carries `CA:TRUE` and `extendedKeyUsage=serverAuth`
explicitly, which is what these two screens require:

- **Android** — Settings → Security → Encryption & credentials → Install a
  certificate → **CA certificate**. Android then shows a standing "network may
  be monitored" notice; that is expected for any privately-signed certificate
  and does not mean anything is wrong.
- **iPhone/iPad** — open the `.crt`, install the profile under Settings →
  General → VPN & Device Management, **then** enable it under Settings →
  General → About → Certificate Trust Settings. Missing that second screen is
  the usual reason an iPhone still refuses a certificate it has installed.

Skipping the certificate step is survivable: the traffic is encrypted either
way, users just meet a warning page each time.

Copy `lms.crt` freely — it is the public half. **Never copy `lms.key`.**
