# Run the LMS on an Ubuntu Virtual Machine — Step-by-Step Guide

This guide takes you from **nothing** to the **LGU Alicia Leave Management System
running on an Ubuntu virtual machine (VM)**, opened from the browser on your own PC.
No Linux experience is assumed. Type (or paste) the commands exactly as shown.

> The Windows/XAMPP version is described in [`RUN_ON_YOUR_PC.md`](RUN_ON_YOUR_PC.md).
> This guide is the Linux version. The application is the same; only the way it is
> installed differs. One script — `deploy/ubuntu/install.sh` — does almost everything.

**What you will end up with**

```
 Your PC (Windows)                         Ubuntu VM (the server)
 ┌──────────────────────┐   your network   ┌────────────────────────────────────┐
 │ browser              │ ───────────────► │ Apache (HTTPS :443) ─► Laravel app │
 │ https://onealicialms │                  │ MariaDB (database, local only)     │
 │        .lan          │                  │ queue worker  (sends OTP codes)    │
 └──────────────────────┘                  │ cron scheduler (accrual, backups)  │
                                           └────────────────────────────────────┘
```

**Time needed:** about 45 minutes, most of it waiting for downloads.

---

## Part A — Create the virtual machine

These steps use **VirtualBox** (free). VMware Workstation, Hyper-V, Proxmox or a cloud
VM work too — the settings are the same idea.

### A1. Download what you need (on your PC)

1. **VirtualBox** — https://www.virtualbox.org/wiki/Downloads → *Windows hosts*. Install
   it with the default options.
2. **Ubuntu Server 24.04 LTS** — https://ubuntu.com/download/server → *Download Ubuntu
   Server 24.04 LTS*. You get a file like `ubuntu-24.04.x-live-server-amd64.iso`
   (about 3 GB).

> Use **24.04 LTS**. 22.04 also works (the installer adds a newer PHP for it), but
> 24.04 is what the installer is tested on.

### A2. Create the VM

1. Open VirtualBox → **New**.
2. **Name:** `lms-server`. **ISO Image:** choose the Ubuntu `.iso` you downloaded.
   Tick **Skip Unattended Installation** (you will do the install yourself — it is
   easy, and you get to choose your username).
3. **Hardware:** Memory **4096 MB** (2048 MB is the minimum), Processors **2**.
4. **Hard disk:** **25 GB** (the default "dynamically allocated" is fine).
5. Click **Finish**. Do **not** start it yet.

### A3. Set the network to "Bridged" — important

This makes the VM a real machine on your network, with its own IP address, so your PC
(and any other office PC or phone) can reach it.

1. Select the VM → **Settings** → **Network** → **Adapter 1**.
2. **Attached to:** **Bridged Adapter**.
3. **Name:** pick the network card your PC actually uses (Wi-Fi or Ethernet).
4. **OK**.

> Can't use Bridged (e.g. a locked-down laptop)? See **Part I → "Using NAT instead
> of Bridged"** at the end. Everything else in this guide stays the same.

### A4. Install Ubuntu Server

Select the VM → **Start**. The Ubuntu installer runs in text mode — use the **arrow
keys**, **Space** to tick, **Enter** to confirm, **Tab** to move between fields.

| Screen | What to choose |
|--------|----------------|
| Language | **English** |
| Installer update available | **Continue without updating** |
| Keyboard | your layout (usually **English (US)**) |
| Type of install | **Ubuntu Server** |
| Network | It shows an address like `192.168.1.50/24` next to your adapter. **Write that IP down.** Then **Done**. |
| Proxy | leave empty → **Done** |
| Mirror | leave the default → **Done** |
| Storage | **Use an entire disk** → **Done** → **Done** → **Continue** (it only erases the VM's virtual disk, not your PC) |
| Profile | Your name, Server name `lms-server`, a **username** (e.g. `lmsadmin`) and a **password**. Remember them. |
| Ubuntu Pro | **Skip for now** |
| SSH | **tick "Install OpenSSH server"** (lets you copy-paste commands from your PC) |
| Featured snaps | tick nothing → **Done** |

Wait for **"Installation complete!"**, then choose **Reboot Now**. If it asks you to
remove the installation medium, just press **Enter**.

Log in at the `lms-server login:` prompt with the username and password you chose.
(The password does not show while you type — that is normal on Linux.)

---

## Part B — Connect to the VM from your PC (recommended)

The VirtualBox window does not support copy-paste well. Connecting with **SSH** from
your PC's terminal does, and makes the rest of this guide much easier.

1. **In the VM**, find its IP address:
   ```bash
   hostname -I
   ```
   The first number, e.g. `192.168.1.50`, is the VM's address. (Use this one in all
   the steps below wherever you see `192.168.1.50`.)
2. **On your PC**, open **Command Prompt** or **PowerShell** and type (with your own
   username and IP):
   ```bash
   ssh lmsadmin@192.168.1.50
   ```
   Type `yes` the first time, then your Ubuntu password. You now have a terminal on
   the VM where **right-click pastes**.

Bring the VM up to date (it asks for your password because of `sudo` — "run as
administrator"):

```bash
sudo apt update && sudo apt upgrade -y
```

---

## Part C — Put the project on the VM

The project must go in **`/var/www/lms`**. (Ubuntu keeps home folders private, so the
web server could not read it from `/home/...`. The installer checks this for you.)

### Option 1 — `git clone` (simplest)

```bash
sudo apt install -y git
```

```bash
sudo git clone https://github.com/jannces/DJ.git /var/www/lms
```

The repository is **private**, so Git asks for a username and password:

- **Username:** your GitHub username.
- **Password:** a **Personal Access Token**, not your GitHub password. Make one at
  GitHub → your picture → **Settings** → **Developer settings** → **Personal access
  tokens** → **Fine-grained tokens** → **Generate new token** → *Repository access:
  Only select repositories → DJ* → *Permissions → Contents: Read-only* → **Generate**.
  Copy the token and paste it as the password (it will not show).

> **Which branch?** The Ubuntu files live on the branch
> `claude/repository-review-840cwj` until it is merged into `main`. Until then, clone
> that branch:
> ```bash
> sudo git clone -b claude/repository-review-840cwj https://github.com/jannces/DJ.git /var/www/lms
> ```

### Option 2 — copy a ZIP from your PC

1. On GitHub, open the repository → **Code** → **Download ZIP**.
2. On your PC (Command Prompt, in the folder holding the ZIP):
   ```bash
   scp DJ-main.zip lmsadmin@192.168.1.50:~
   ```
3. On the VM:
   ```bash
   sudo apt install -y unzip
   ```
   ```bash
   unzip ~/DJ-main.zip -d ~ && sudo mv ~/DJ-main /var/www/lms
   ```
   (The folder inside the ZIP may have a slightly different name — check with `ls ~`.)

Check it worked — you should see `artisan`, `app`, `deploy`, `public` and others:

```bash
ls /var/www/lms
```

---

## Part D — Run the installer

```bash
cd /var/www/lms
```

```bash
sudo bash deploy/ubuntu/install.sh --demo
```

- `--demo` adds sample employees and one account per role, which is what you want for
  learning and testing. **Leave it off for a real office install.**
- It takes **5–15 minutes** (it downloads PHP libraries). You will see nine numbered
  steps go by:

| Step | What it does |
|------|--------------|
| 1/9 | Installs **Apache** (web server), **PHP 8.3**, **MariaDB** (MySQL-compatible database), **Composer** |
| 2/9 | Creates the database `lms_alicia` and a database user `lms` with a random password |
| 3/9 | Writes the settings file `.env` (production mode, HTTPS address, database login) |
| 4/9 | Installs the PHP libraries, creates all tables, adds roles, the 15 CSC leave types, holidays, settings and the Super Admin (plus demo data with `--demo`) |
| 5/9 | Makes an HTTPS certificate for `onealicialms.lan` **and** the VM's IP address |
| 6/9 | Sets up the Apache site: HTTP → HTTPS, only your local network may connect, 10 MB uploads |
| 7/9 | Starts the **queue worker** (it sends the login codes) and the **scheduler** (monthly leave accrual, nightly backup) — both start again by themselves after a reboot |
| 8/9 | Turns on the firewall, allowing only SSH, HTTP and HTTPS |
| 9/9 | Installs the `lms` helper command |

At the end it prints a box like this. **Copy it somewhere safe:**

```
  Address          : https://onealicialms.lan   or   https://192.168.1.50
  Database         : lms_alicia  user lms  password ************
  Super admin      : username superadmin  (superadmin@alicia.gov.ph)
  First password   : Alicia-xxxxxxxxxx!9   (you must change it at first login)
  ...
  On every PC that will use the system, add this line to its hosts file:
      192.168.1.50   onealicialms.lan
```

Forgot to copy it? `sudo lms info` shows it again.

Check everything is running:

```bash
sudo lms status
```

All three — `mariadb`, `apache2`, `lms-queue` — should say **running**, and the
database check should say **OK**.

---

## Part E — Open the system from your PC

### E1. Quick test with the IP address

On your PC, open Chrome or Edge and go to **`https://192.168.1.50`** (your VM's IP).

You will see **"Your connection is not private"**. That is expected: the certificate
was made by your server, not bought from a public company. Click **Advanced** →
**Continue to 192.168.1.50 (unsafe)**. The connection is still encrypted.

You should see the **LGU Alicia LMS login page**. 🎉

### E2. Use the proper name `onealicialms.lan`

Your PC needs to be told which machine that name belongs to.

1. Press the Windows key, type **Notepad**, right-click it → **Run as administrator**.
2. **File → Open**, go to `C:\Windows\System32\drivers\etc`, change the file filter
   from *Text Documents* to **All Files**, and open **`hosts`**.
3. Add this line at the bottom (your VM's IP), then **Save**:
   ```
   192.168.1.50   onealicialms.lan
   ```
4. Open **`https://onealicialms.lan`**.

(macOS / Linux PCs: `sudo nano /etc/hosts` and add the same line.)

### E3. Stop the certificate warning (optional, recommended)

Copy the certificate — the **public** half, safe to copy — from the VM to your PC.
In Command Prompt on your PC:

```bash
scp lmsadmin@192.168.1.50:/var/www/lms/deploy/certs/lms.crt .
```

Then open **PowerShell as administrator** in that same folder and run:

```bash
Import-Certificate -FilePath .\lms.crt -CertStoreLocation Cert:\LocalMachine\Root
```

Close **all** browser windows and open `https://onealicialms.lan` again — the padlock
is now clean. (Never copy `lms.key`: that is the private half and stays on the server.)

> **Phones/tablets:** see "Phones and tablets" in [`deploy/README.md`](../deploy/README.md).
> The same `lms.crt` file is used.

---

## Part F — First login

1. On the login page enter **`superadmin`** and the **first password** from the
   installer's summary.
2. The system now asks for a **6-digit one-time code (OTP)**. Email is not set up yet,
   so the code is written to the server's log instead. **On the VM**, run:
   ```bash
   sudo lms otp
   ```
   It shows the newest code. Type it on the OTP screen (codes expire after 5 minutes).
3. You are asked to **choose a new password** — at least 12 characters with a capital
   letter, a small letter, a number and a symbol (e.g. `MyStr0ng!Pass2026`).

### Demo accounts (only with `--demo`)

All demo passwords are **`Alicia@2026Demo!`** (each asks for a new password at first
login, and each needs an OTP from `sudo lms otp`).

| Role | Username | Email |
|------|----------|-------|
| System Administrator | `sysadmin` | `sysadmin@alicia.gov.ph` |
| HR Officer | `hrofficer` | `hr@alicia.gov.ph` |
| Department Head | `depthead` | `depthead@alicia.gov.ph` |
| Municipal Mayor | `mayor` | `mayor@alicia.gov.ph` |
| Employee | `employee` | `employee@alicia.gov.ph` |

> **Tip for testing:** as Super Admin, **Administration → System Settings** →
> turn **`auth.otp_enabled`** OFF to skip the codes. Turn it back ON for real use.

Things to try: as **Employee** apply for a Vacation Leave; as **HR**/**Mayor** decide it;
open the decided request and print the **CSC Form 6 (PDF)**; as **System Administrator**
open the **Security Dashboard**.

---

## Part G — Everyday use

All of these run **on the VM**:

| Command | What it does |
|---------|--------------|
| `sudo lms status` | Is everything running? Shows the address and hosts-file line |
| `sudo lms stop` | Stop the system (database, web server, worker) |
| `sudo lms start` | Start it again |
| `sudo lms restart` | Stop + start |
| `sudo lms otp` | Show the newest one-time login code |
| `sudo lms logs` | Watch the application log live (Ctrl+C to quit) |
| `sudo lms backup` | Back up the database + uploaded documents to `storage/app/backups` |
| `sudo lms update` | Back up, download the new version (`git pull`), install, migrate, restart |
| `sudo lms info` | Show the install summary again |
| `sudo lms artisan ...` | Any `php artisan` command, run safely as the web-server user |

**Turning the VM off and on:** `sudo poweroff` shuts it down cleanly. When you start
the VM again, everything starts by itself — you only need to log in to the VM if you
want to run one of the commands above.

> Always shut the VM down with `sudo poweroff` (or VirtualBox → *ACPI Shutdown*),
> not by closing the window with *Power off the machine*. Pulling the plug on a
> database is how tables get damaged.

---

## Part H — Before real office use

1. **Install without `--demo`.** For a real deployment start from a fresh VM and run
   `sudo bash deploy/ubuntu/install.sh` (no `--demo`).
2. **Give the server a fixed IP address.** Ask whoever runs the router for a **DHCP
   reservation** for the VM. If the IP ever changes, just run the installer again —
   it detects the new address, re-issues the certificate and updates Apache; your
   data and passwords are kept. Then update the hosts line on every PC and re-import
   the certificate (Part E).
3. **Real email for OTP codes.** Edit the settings on the VM:
   ```bash
   sudo nano /var/www/lms/.env
   ```
   Change `MAIL_MAILER=log` to `MAIL_MAILER=smtp` and fill in `MAIL_HOST`, `MAIL_PORT`,
   `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` from your mail provider. Save
   (Ctrl+O, Enter, Ctrl+X) and run `sudo lms restart`.
4. **Authorized devices.** Register the office PCs under **Admin → Authorized
   Devices**, then turn on `security.device_enforcement` in System Settings
   (see [`Deployment.md`](Deployment.md) §5).
5. **Backups off the server.** A backup runs every night at 01:00 into
   `/var/www/lms/storage/app/backups`. Copy those ZIPs somewhere else regularly, e.g.
   from your PC: `scp lmsadmin@192.168.1.50:/var/www/lms/storage/app/backups/*.zip .`
6. **Keep Ubuntu patched:** `sudo apt update && sudo apt upgrade -y` monthly.

---

## Part I — Troubleshooting

| Problem | Fix |
|---------|-----|
| Browser: *"This site can't be reached"* | Is the VM running? Does `sudo lms status` show everything running? Is the network **Bridged** (A3)? Can your PC `ping 192.168.1.50`? |
| `onealicialms.lan` doesn't load but the IP does | The hosts-file line is missing or has the wrong IP (E2). In Command Prompt run `ipconfig /flushdns`. |
| **403 Forbidden** | Your PC is on a different network than the VM. Only the VM's own subnet is allowed. Add yours to `Require ip` in `/etc/apache2/sites-available/lms.conf`, then `sudo systemctl reload apache2`. |
| **500 Server Error** | `sudo tail -50 /var/www/lms/storage/logs/laravel.log` shows why. Re-running the installer fixes file-permission problems. |
| No OTP / `sudo lms otp` finds nothing | `sudo lms status` — `lms-queue` must be **running**. If not: `sudo systemctl restart lms-queue`. |
| **419 Page Expired** | Open the site with `https://`, not `http://`; refresh the login page and try again. |
| Certificate warning came back | The VM's IP changed and the certificate was re-issued. Import the new `lms.crt` again (E3). |
| Locked out after 3 wrong passwords | Wait 24 hours, or have another administrator unblock the account under **Users**. |
| The IP address of the VM changed | Run `cd /var/www/lms && sudo bash deploy/ubuntu/install.sh` again, then fix the hosts line on each PC. |

### Using NAT instead of Bridged

If you cannot use a Bridged adapter, VirtualBox's default **NAT** works for using the
system **from your own PC only**:

1. VM **Settings → Network → Adapter 1 → Attached to: NAT → Advanced → Port Forwarding**.
2. Add two rules:

   | Name | Protocol | Host IP | Host Port | Guest Port |
   |------|----------|---------|-----------|-----------|
   | https | TCP | 127.0.0.1 | 8443 | 443 |
   | ssh   | TCP | 127.0.0.1 | 2222 | 22 |

3. SSH in with `ssh -p 2222 lmsadmin@127.0.0.1`, run Parts C and D as written.
4. Open **`https://localhost:8443`** on your PC (skip the hosts-file step).
   To copy the certificate: `scp -P 2222 lmsadmin@127.0.0.1:/var/www/lms/deploy/certs/lms.crt .`

### Start completely fresh (deletes all data!)

```bash
sudo lms artisan migrate:fresh --seed --force
```

### Where things are on the server

| What | Where |
|------|-------|
| Project | `/var/www/lms` |
| Settings | `/var/www/lms/.env` |
| Application log | `/var/www/lms/storage/logs/laravel.log` |
| Apache site | `/etc/apache2/sites-available/lms.conf` |
| Apache logs | `/var/log/apache2/lms-error.log`, `lms-access.log` |
| Queue worker | `systemctl status lms-queue` (`/etc/systemd/system/lms-queue.service`) |
| Scheduler | `/etc/cron.d/lms` |
| Certificate | `/var/www/lms/deploy/certs/lms.crt` (public), `lms.key` (private) |
| Backups | `/var/www/lms/storage/app/backups` |
| Install summary | `/root/lms-install-info.txt` (`sudo lms info`) |
