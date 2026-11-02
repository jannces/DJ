# Troubleshooting XAMPP — "MySQL shutdown unexpectedly"

Apache green, MySQL red, and this in the Control Panel log:

```
Error: MySQL shutdown unexpectedly.
This may be due to a blocked port, missing dependencies,
improper privileges, a crash, or a shutdown by another method.
```

That message lists five possible causes and commits to none of them. **Do not start
deleting things.** The real reason is in the error log — find it first.

---

## Step 1 — Read the actual error (always do this first)

In the XAMPP Control Panel, click **Logs** on the MySQL row → **mysql_error.log**.
Or open it directly:

```
C:\xampp\mysql\data\mysql_error.log
```

Scroll to the **bottom** — the last `[ERROR]` lines before it gave up are the ones that
matter. Match what you see against the table below.

| What the log says | Cause | Go to |
|---|---|---|
| `Can't start server: Bind on TCP/IP port`, `Address already in use`, `Do you already have another mysqld server running` | Port 3306 is taken | Step 2 |
| `Unable to lock ./ibdata1, error: 11` | A second MySQL is already running | Step 2 |
| `InnoDB: Database page corruption`, `InnoDB: Cannot open datafile`, `Operating system error number 13`, `Table 'mysql.plugin' doesn't exist` | Unclean shutdown / corrupted data directory | Step 3 |
| `outside the tablespace bounds`, `space name innodb_system`, `mysqld got exception` | `ibdata1` is truncated or mismatched — unrecoverable in place | Step 3, via Step 3b |
| `Aria engine: log initialization failed`, `Can't init tc log` | Stale log files | Step 4 |
| Nothing useful, or the log is empty | Permissions or antivirus | Step 5 |

---

## Step 2 — Something else is already using port 3306

Most often a standalone **MySQL Server** or **MariaDB** installed separately (MySQL
Workbench installs one), running as a Windows service that starts at boot.

Open Command Prompt and find what holds the port:

```
netstat -ano | findstr :3306
```

Take the number in the last column (the PID) and identify it:

```
tasklist /fi "pid eq 1234"
```

Then either:

**(a) Stop the other service** — press `Win+R`, type `services.msc`, find the service
named `MySQL`, `MySQL80` or `MariaDB`, right-click → **Stop**, and set
**Startup type: Manual** so it stops fighting XAMPP at every boot.

**(b) Move XAMPP's MySQL to another port.** Edit `C:\xampp\mysql\bin\my.ini`, change
**both** occurrences of `port=3306` (under `[client]` and `[mysqld]`) to `3307`, then
restart MySQL.

> If you choose (b), you **must** also tell the application, or it will keep dialling
> 3306 and report a connection error:
>
> ```
> DB_PORT=3307
> ```
>
> in `.env`, then `php artisan config:clear`. phpMyAdmin needs
> `$cfg['Servers'][$i]['port'] = '3307';` in `C:\xampp\phpMyAdmin\config.inc.php`.

---

## Step 3 — Corrupted data directory (the common one)

Usually caused by the PC being shut down, losing power, or being put to sleep while
MySQL was mid-write. XAMPP keeps a clean copy of the system tables in
`C:\xampp\mysql\backup`, and recovery means rebuilding the data directory from it.

### Back up first — always

With MySQL **stopped**, copy the whole folder somewhere safe:

```
xcopy /E /I /H C:\xampp\mysql\data C:\xampp\mysql\data_backup_%DATE:~-4%%DATE:~4,2%%DATE:~7,2%
```

Nothing below is reversible without this copy.

### Option A — Rebuild and re-seed (recommended for this project)

This system's database is **fully reproducible from the seeders**, so the fastest and
most reliable fix is to stop trying to rescue the old data directory:

1. Stop MySQL in the XAMPP Control Panel (make sure no `mysqld.exe` is left in Task
   Manager).
2. Rename `C:\xampp\mysql\data` to `C:\xampp\mysql\data_old`.
3. Copy `C:\xampp\mysql\backup` and rename the copy to `C:\xampp\mysql\data`.
4. Start MySQL — it should go green.
5. Recreate the database at **http://localhost/phpmyadmin** → **New** → `lms_alicia`.
6. Rebuild the schema and data:

   ```
   php artisan migrate:fresh --seed
   php artisan db:seed --class=DemoDataSeeder
   ```

You lose any leave requests you entered by hand, but roles, permissions, the 15 CSC
leave types, holidays, settings, the Super Admin account and the demo employees all
come straight back. For a system you are still testing, this is almost always the right
trade.

### Option B — Keep the existing data

Only worth the risk if you have hand-entered records you cannot recreate.

Steps 1–3 as above, then **before starting MySQL**:

4. From `data_old`, copy your own database folders into the new `data` —
   `lms_alicia`, and any other project databases. Do **not** copy the `mysql`,
   `performance_schema` or `phpmyadmin` folders; those are the system tables you just
   restored from `backup`.
5. Copy `ibdata1` from `data_old` into `data`, overwriting the one there.

   > This file is not optional. Every InnoDB table's structure lives in it, so copying
   > the database folders without it gives you `Table doesn't exist` for every table
   > even though the files are sitting right there.

6. Start MySQL.

If it still will not start after step 6, the corruption is in `ibdata1` itself. Go back
to Option A — the data is not coming back, and the seeders will rebuild it.

---

## Step 3b — Salvaging data from a corrupt `ibdata1`

A log line like this is the end of the road for the data directory as it stands:

```
[ERROR] [FATAL] InnoDB: Trying to read page number 212993 in space 0,
space name innodb_system, which is outside the tablespace bounds.
[ERROR] mysqld got exception 0x80000003
```

`space 0` is `ibdata1` itself — the InnoDB system tablespace. InnoDB is being told by
its own metadata to read a page far beyond the end of the file, so it asserts and dies
before it can serve anything. The file is truncated, or it does not match the `.ibd`
files and redo logs sitting beside it. Nothing you change in `.env` or the Control Panel
will fix it: the fix is a new data directory (Step 3).

**Common causes** — worth knowing, because they recur:

- The disk filled up mid-write. Check free space on `C:` before rebuilding, or it will
  happen again.
- `ibdata1` was restored from a different backup than the database folders, or copied
  without them. The two are a matched set.
- Antivirus or a sync client (OneDrive, Google Drive) touched `C:\xampp\mysql\data`.
- The PC was powered off or slept mid-transaction.

### Try to rescue the data first — only if you need it

If the database holds records you cannot recreate from the seeders, it is worth one
attempt before rebuilding. Back up `C:\xampp\mysql\data` first (Step 3), then open
`C:\xampp\mysql\bin\my.ini`, and under `[mysqld]` add:

```ini
innodb_force_recovery = 1
```

Start MySQL. If it stays red, stop it, raise the number by one and try again, up to `6`.

> Levels `1`–`3` are relatively safe. From `4` upward InnoDB skips integrity work and can
> return corrupt rows, so treat anything that starts at `4`+ as read-only: dump and get
> out. Do not run migrations, seeders or the application against a server in forced
> recovery.

If MySQL comes up at any level, dump immediately:

```
C:\xampp\mysql\bin\mysqldump -u root --databases lms_alicia > C:\lms_rescue.sql
```

Then remove the `innodb_force_recovery` line from `my.ini`, rebuild the data directory
(Step 3, Option A), and import what you rescued:

```
C:\xampp\mysql\bin\mysql -u root < C:\lms_rescue.sql
```

If the dump is empty, errors out, or MySQL will not start even at `6`, stop — the data
is gone. Rebuild with Step 3 Option A and re-seed.

---

## Step 4 — Stale log files

If the log complains about Aria or the transaction coordinator, stop MySQL and delete
these from `C:\xampp\mysql\data` (they are regenerated at startup):

```
aria_log.00000001
aria_log_control
ib_logfile0
ib_logfile1
tc.log
```

Leave `ibdata1` alone — deleting that destroys every InnoDB table.

---

## Step 5 — Permissions and antivirus

- **Run as Administrator.** Right-click the XAMPP Control Panel → *Run as administrator*.
  Installing XAMPP under `C:\Program Files` causes permission failures; `C:\xampp` is the
  supported location.
- **Antivirus / Controlled Folder Access.** Windows Defender's ransomware protection and
  most third-party antivirus lock `mysqld.exe` out of its own data directory. Add
  `C:\xampp` as an exclusion.
- **Check for a zombie process.** Task Manager → Details → if `mysqld.exe` is listed
  while the Control Panel shows MySQL stopped, end it, then start MySQL again.

---

## After MySQL is green again

Confirm the application can actually reach it:

```
php artisan migrate:status
```

A table of migrations means the connection works. `SQLSTATE[HY000] [2002]` means MySQL
is still not listening on the port in `.env`.

Then carry on with `php artisan lms:lan` (see `docs/LAN-Access.md`) if you are setting up
access from other devices.

---

## Preventing it

- Always stop MySQL from the XAMPP Control Panel before shutting the PC down. Sleep and
  hibernate while MySQL is running are the usual causes of a corrupted data directory.
- Take real backups — this project ships one:

  ```
  php artisan lms:backup
  ```

  It writes a timestamped zip to `storage/app/backups` and is scheduled daily at 01:00
  (see `docs/Deployment.md` §6). A backup inside the data directory you just lost is not
  a backup; copy them off the machine.
