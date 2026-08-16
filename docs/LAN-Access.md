# Accessing the System from Another Laptop (LAN / IP address)

Goal: the system runs on **one PC (the server)** with XAMPP, and other laptops on the
same Wi-Fi/switch open it in a browser using the server's **IP address**, e.g.
`http://192.168.1.10`.

Both machines must be on the **same network** (same router / same switch). No internet
is required.

---

## Quick check: `php artisan lms:lan`

Run this on the server first — it prints the URLs other devices should open and
checks the settings that silently break access:

```
php artisan lms:lan
```

```
  LGU Alicia LMS — access from another device

  On the other device — same Wi-Fi or switch — open:

    http://192.168.1.14:8000   Wi-Fi
    http://10.0.5.22:8000      Ethernet

  Start the server with:

    php artisan serve --host=0.0.0.0 --port=8000

  Checks
  [FAIL] SESSION_SECURE_COOKIE=true, but you are serving over plain http://
  [ ok ] Config is not cached — .env edits take effect immediately.
  [ ok ] Device enforcement is OFF — any LAN address may connect.
```

Use `--port=80` when serving through Apache, and `--https` for the certificate-based
deployment. The command exits non-zero if it finds a blocker, and each `[FAIL]` /
`[warn]` line names the fix. The rest of this guide explains each step in full.

---

## Step 1 — Find the server's IP address

`php artisan lms:lan` prints this for you. To read it yourself, open Command Prompt on
the **server PC** and run:

```
ipconfig
```

Look under your active adapter (Wi-Fi or Ethernet) for **IPv4 Address**, e.g.:

```
IPv4 Address. . . . . . . . . . . : 192.168.1.10
```

That number is what the other laptops will type. Throughout this guide it is written as
`192.168.1.10` — replace it with your own.

> **Reserve the IP.** Routers hand out IPs temporarily (DHCP), so the server's IP can
> change after a reboot and break everyone's bookmark. Either set a **static IP** in
> Windows (Network Settings → Change adapter options → Properties → IPv4) or add a
> **DHCP reservation** in the router for the server's MAC address.

---

## Step 2 — Point the app at that IP

Open `.env` on the server and set:

```
APP_URL=http://192.168.1.10

# Plain HTTP over the LAN: the session cookie MUST NOT be HTTPS-only,
# otherwise logins silently fail (you get bounced back to the login page).
SESSION_SECURE_COOKIE=false
```

Then refresh the cached config:

```
php artisan config:clear
php artisan config:cache
```

> ⚠️ **This is the single most common cause of "I can see the login page from the other
> laptop but it won't let me in."** `.env.example` ships with `SESSION_SECURE_COOKIE=true`
> because the documented production setup uses HTTPS. Over plain `http://` the browser
> refuses to store a `Secure` cookie, so the session — and the CSRF token with it — is
> thrown away on every request. Set it to `false` for HTTP, or do
> [Step 6](#step-6--optional-but-recommended-use-https-instead) and keep it `true`.

---

## Step 3 — Serve the app on the network

Pick **one** of the two options below.

### Option A — Quick (testing / demo): `php artisan serve`

By default `php artisan serve` listens on `127.0.0.1` only, so no other machine can
reach it. Bind it to all interfaces:

```
php artisan serve --host=0.0.0.0 --port=8000
```

Other laptops then open:

```
http://192.168.1.10:8000
```

Leave the Command Prompt window open. This is fine for a demo or defense, but it is a
single-threaded development server — use Option B for real office use.

### Option B — Proper (real deployment): Apache VirtualHost

The repo now ships `deploy/apache-vhost-ip.conf` for exactly this case.

1. Open `C:\xampp\apache\conf\extra\httpd-vhosts.conf` in Notepad.
2. Add this line at the bottom (adjust the path if your project folder differs):

   ```apache
   Include "C:/xampp/htdocs/lms/deploy/apache-vhost-ip.conf"
   ```

3. Open `deploy/apache-vhost-ip.conf` and edit two things:
   - `DocumentRoot` / `<Directory>` — must point at the project's **`public`** folder.
   - `Require ip 192.168.1.0/24` — must match **your** subnet (see the note in the file).
4. Make sure `mod_rewrite` is enabled in `C:\xampp\apache\conf\httpd.conf`
   (the line `LoadModule rewrite_module modules/mod_rewrite.so` must have no `#`).
5. In XAMPP Control Panel, click **Stop** then **Start** on Apache.

Other laptops then open:

```
http://192.168.1.10
```

(no port number — port 80 is the default).

> XAMPP's own pages (`/dashboard`, `/phpmyadmin`) stay locked to the server PC. That is
> deliberate — do not expose phpMyAdmin to the LAN.

---

## Step 4 — Open the Windows Firewall

Windows blocks incoming connections by default, so the other laptop will just time out.
On the **server PC**, open Command Prompt **as Administrator** and run the rule matching
your option:

```
:: Option B (Apache on port 80)
netsh advfirewall firewall add rule name="LMS HTTP 80" dir=in action=allow protocol=TCP localport=80

:: Option A (artisan serve on port 8000)
netsh advfirewall firewall add rule name="LMS HTTP 8000" dir=in action=allow protocol=TCP localport=8000
```

Also make sure Windows treats the office network as **Private**, not Public
(Settings → Network & Internet → your network → Network profile → **Private**).

Do **not** open port 3306 — MySQL should stay bound to `127.0.0.1`.

---

## Step 5 — Register the other laptop as an authorized device

This system has an IP allow-list (`AuthorizedDeviceMiddleware`, ADR-006). If
**`security.device_enforcement`** is ON in System Settings, any IP that is not registered
gets a **403 "Unauthorized device"** page and the attempt is written to the intrusion log.

On the **server**, log in as Super Admin (`127.0.0.1` is always pre-authorized) and:

1. Find the other laptop's IP — run `ipconfig` on **that** laptop.
2. Go to **Administration → Authorized Devices → Add**.
3. Enter its IP address, a label (e.g. "HR Office Laptop"), and make sure it is **Active**.

Repeat for each workstation. The allow-list is cached for 60 seconds, so wait a minute
(or restart Apache) before testing a newly added device.

> If you are still setting up and want everything reachable first, leave
> `security.device_enforcement` **OFF** in **Administration → System Settings**, get the
> LAN access working, then register the devices and turn it back **ON**. It is a real
> security control — don't ship the system with it off.

---

## Step 6 — (Optional but recommended) Use HTTPS instead

Plain HTTP means passwords and OTP codes travel the office network in the clear. For the
real deployment, follow **`docs/Deployment.md` §3**: generate a self-signed certificate
with `deploy/make-cert.bat`, use `deploy/apache-vhost.conf`, add
`192.168.1.10  lms.alicia.local` to each laptop's `hosts` file (or your LAN DNS), and
import `lms.crt` into each laptop's trust store.

With HTTPS you keep `SESSION_SECURE_COOKIE=true` and set
`APP_URL=https://lms.alicia.local`.

---

## Troubleshooting

| Symptom on the other laptop | Cause | Fix |
|---|---|---|
| Page never loads / "took too long to respond" | Firewall, or `artisan serve` bound to localhost | Step 4; and use `--host=0.0.0.0` (Step 3A) |
| "Unable to connect" instantly | Apache not running, or wrong IP | Start Apache in XAMPP; re-check `ipconfig` |
| Login page shows, but submitting just returns to the login page | `SESSION_SECURE_COOKIE=true` over HTTP | Step 2 |
| **419 Page Expired** | Same cookie problem as above | Step 2, then `php artisan config:clear` |
| **403 Forbidden** (Apache page) | `Require ip` doesn't match your subnet | Edit `deploy/apache-vhost-ip.conf` (Step 3B.3) |
| **403 "Unauthorized device"** (styled app page) | Device enforcement is on and the IP isn't registered | Step 5 |
| Page loads but has no styling / broken layout | `APP_URL` still points at the old hostname | Step 2, then `php artisan config:cache` |
| Works from the server, not from the laptop | Different networks (e.g. laptop on guest Wi-Fi) | Put both on the same network/VLAN |
| Worked yesterday, not today | Server's DHCP address changed | Reserve a static IP (Step 1) |

On the server, `php artisan lms:lan` diagnoses every row above except the firewall
(which cannot be checked from inside PHP).

Quick check from the other laptop — open Command Prompt and run:

```
ping 192.168.1.10
```

If ping fails, it's a network/firewall problem, not an application problem.

---

## Reminder about `php artisan serve --host=0.0.0.0`

`0.0.0.0` means "listen on every network interface". Combined with the firewall rule it
makes the dev server reachable from the LAN. Only do this on a trusted office network —
never on public Wi-Fi.
