# How to Run This System on Your PC — Beginner's Step-by-Step Guide

This guide assumes you have **never** run a Laravel project before. Follow every
step in order. Copy-paste the commands exactly. Words in `code font` are things
you type or click.

> The system is the **Cybersecurity Integrated Digital Leave Management System**
> for the LGU of Alicia. It runs on your computer and on your office network (LAN).

---

## Part A — Install the tools you need (one time)

You need three things: **PHP + MySQL (XAMPP)**, **Composer**, and **Git**.

### 1. Install XAMPP (gives you PHP, Apache and MySQL)
1. Go to https://www.apachefriends.org and download **XAMPP for Windows** with
   **PHP 8.3 or newer**.
2. Run the installer. Keep clicking **Next** (default options are fine).
3. Install it to `C:\xampp` (the default).

### 2. Install Composer (installs the project's PHP libraries)
1. Go to https://getcomposer.org/download and download **Composer-Setup.exe**.
2. Run it. When it asks for the PHP location, choose `C:\xampp\php\php.exe`.
3. Keep clicking **Next** until it finishes.

### 3. Install Git (to download the project)
1. Go to https://git-scm.com/download/win and install Git (default options).

> ✅ To check they installed: open **Command Prompt** (press the Windows key,
> type `cmd`, press Enter) and run these one at a time — each should print a version:
> ```
> php --version
> composer --version
> git --version
> ```

---

## Part B — Get the project onto your PC

1. Open **XAMPP Control Panel** (Start menu → XAMPP).
2. Click **Start** next to **Apache** and next to **MySQL**. Both should turn green.
3. Open **Command Prompt** and go to the XAMPP web folder:
   ```
   cd C:\xampp\htdocs
   ```
4. Download the project (replace the URL with your repository if different):
   ```
   git clone <YOUR-REPOSITORY-URL> lms
   cd lms
   ```
   *(If you already have the project as a ZIP, just unzip it into
   `C:\xampp\htdocs\lms` instead, then `cd C:\xampp\htdocs\lms`.)*

---

## Part C — Set up the project

Run these commands **one line at a time** inside `C:\xampp\htdocs\lms`:

1. Install the PHP libraries (takes a few minutes):
   ```
   composer install
   ```

2. Create your settings file:
   ```
   copy .env.example .env
   ```

3. Generate the app security key:
   ```
   php artisan key:generate
   ```

### Create the database
4. Open your browser and go to **http://localhost/phpmyadmin**
5. Click **New** on the left, type the database name `lms_alicia`, and click **Create**.

> The default `.env` already uses username `root` with an empty password, which is
> what fresh XAMPP uses. If you set a MySQL password, open `.env` in Notepad and put
> it after `DB_PASSWORD=`.

### Create the tables and starter data
6. Back in Command Prompt:
   ```
   php artisan migrate --seed
   ```
   This creates all the tables and adds the roles, permissions, the 15 CSC leave
   types, Philippine holidays, default settings, and the **Super Admin** account.

7. (Recommended for a demo) Add sample employees and one account per role:
   ```
   php artisan db:seed --class=DemoDataSeeder
   ```

8. Link the file storage (for uploaded documents):
   ```
   php artisan storage:link
   ```

---

## Part D — Start the system (easy way)

For learning and testing, the simplest way to run it:

```
php artisan serve
```

Leave that window open. Now open your browser and go to:

**http://127.0.0.1:8000**

You should see the **login page**. 🎉

> To stop the system, click the Command Prompt window and press **Ctrl + C**.

---

## Part E — Log in for the first time

Use one of these seeded accounts (from the demo seeder). **All demo passwords are:**
`Alicia@2026Demo!`

| Role | Email |
|------|-------|
| Super Admin | `superadmin@alicia.gov.ph` (password: `ChangeMe!Alicia2026`) |
| System Administrator | `sysadmin@alicia.gov.ph` |
| HR Officer | `hr@alicia.gov.ph` |
| Department Head | `depthead@alicia.gov.ph` |
| Municipal Mayor | `mayor@alicia.gov.ph` |
| Employee | `employee@alicia.gov.ph` |

### About the OTP (one-time password)
After you type your password, the system emails a **6-digit code**. Since you set
`MAIL_MAILER=log` in `.env` for first setup, the email is **not really sent** — it is
written to a file. To read your code:

1. Open the file `storage\logs\laravel.log` in Notepad.
2. Scroll to the bottom. You'll see the 6-digit code in the latest message.
3. Type that code on the OTP screen.

> **Tip:** To skip OTP entirely while testing, log in as Super Admin once, go to
> **Administration → System Settings**, and turn **`auth.otp_enabled`** OFF.
> (Turn it back ON before real use — it's an important security feature.)

The first time you log in with a seeded account it will ask you to **change your
password**. Choose a strong one (at least 12 characters with a capital letter, a
small letter, a number, and a symbol — for example `MyStr0ng!Pass2026`).

---

## Part F — Try it out

- **As an Employee:** click **Apply for Leave**, pick *Vacation Leave*, choose dates,
  submit. Then open **My Leave Requests** to watch its status.
- **As a Department Head:** open **Department Reviews** and recommend approval.
- **As HR:** open **HR Validation**, certify the credits, and endorse.
- **As the Mayor:** open **Final Approval** and approve — the employee's leave credits
  update automatically and they get a notification.
- **As a System Admin:** open the **Security Dashboard** to see charts, and
  **Authorized Devices** / **Users** to manage the system. Try opening a page you
  don't have access to — you'll see it gets logged as an intrusion attempt.
- Open any decided leave request and click **CSC Form 6 (PDF)** to print the official form.

---

## Part G — Common problems and fixes

| Problem | Fix |
|---------|-----|
| `php` is not recognized | Add `C:\xampp\php` to Windows PATH, or run commands from inside `C:\xampp\htdocs\lms` after restarting Command Prompt. |
| MySQL won't start in XAMPP | Another program is using port 3306. Stop it, or change the port in XAMPP config. |
| "could not find driver" | In XAMPP, edit `C:\xampp\php\php.ini`, remove the `;` before `extension=pdo_mysql`, save, restart Apache. |
| Login says database error | Make sure MySQL is **green** in XAMPP and you created the `lms_alicia` database (Part C step 5). |
| I didn't get the OTP email | Read it from `storage\logs\laravel.log` (Part E), or turn off OTP in System Settings for testing. |
| Page looks unstyled | Run `php artisan storage:link` and refresh; make sure you opened `http://127.0.0.1:8000` (not the `public` folder directly). |
| Locked out (3 wrong passwords) | Wait 24 hours, or ask a Super Admin to unblock you under **Users**, or reset with the CLI (below). |

### Reset everything and start fresh
```
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoDataSeeder
```

### Useful commands
```
php artisan test            # run the automated tests (should say "47 passed")
php artisan lms:backup      # make a backup zip in storage/app/backups
php artisan leave:accrue    # add this month's 1.25 VL + 1.25 SL credits to everyone
```

---

## Part H — Running it for the whole office (real LAN deployment)

When you're ready to let other office computers use it over the network with proper
HTTPS and Apache (not `php artisan serve`), follow **`docs/Deployment.md`** — it has the
Apache VirtualHost, the HTTPS certificate steps, the queue worker/scheduler setup, and a
security hardening checklist. The helper scripts are in the `deploy/` folder.

For day-to-day administration (adding users, registering office computers, reviewing
security), read **`docs/AdminGuide.md`**. For everyday users, **`docs/UserGuide.md`**.

---

Welcome aboard — you now have a full, working government leave-management system running
on your PC. 🇵🇭
