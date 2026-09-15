# Administrator Manual

Audience: Super Admin and System Administrator of LGU Alicia.

## 1. Accounts & first run
- Seeded accounts (rotate immediately): see Deployment.md §5. Default password policy forces
  a change on first login (`must_change_password`).
- Create users under **Admin → Users → Create**: identity, employee profile, department,
  position, salary, role(s). New users receive a welcome email with a temporary password.

## 2. User account management
| Task | Where |
|---|---|
| Create / update / archive / restore / delete | Admin → Users (archive = soft delete + archives register; delete only from the archive view) |
| Reset password | Users → row menu → *Reset password* (emails a reset link; force-change flag set) |
| Assign roles / direct permissions | Users → row menu → *Access* (deny overrides allow) |
| Manual block / unblock | Users → row menu (reason required; audited) — also lifts a 3-strike lockout |
| Activate / deactivate | row menu (deactivated users cannot log in but keep history) |
| Login history / audit history | Users → row menu → *History* |

## 3. Roles & permissions
**Admin → Roles**: create roles, pick a parent (inherited permissions are shown greyed and
locked), tick module permissions. Seeded roles: Super Admin (all), System Administrator,
HR, Department Head, Employee. Menu items appear only when the user holds the mapped
permission — no code changes needed for new roles.

## 4. Authorized devices
**Admin → Devices**: register IP + hostname (+description), activate/deactivate, archive,
search; *Last active* and online/offline (seen within 5 min) shown live. Enforcement toggle:
**Admin → Settings → security.device_enforcement**. The server's own 127.0.0.1 is seeded so
you can never lock yourself out; if you do, run `php artisan lms:device:add <ip> <hostname>`.

## 5. Security operations
- **Security Dashboard** (Admin → Security): today/week/month attack counts, top attackers,
  most-targeted pages, recent alerts, charts; navbar bell polls every 15 s.
- **Blocked IPs**: automatic entries expire per `security.ip_block_hours` (default 24);
  unblock or block manually with reason.
- **Blocked accounts**: after 3 failed logins accounts self-block for 24 h — unblock from
  the Users screen.
- **Intrusion / Audit / Activity logs**: filterable, exportable (Reports module). Audit rows
  are append-only by design.
- Recommended routine: review Security Dashboard each morning; export the weekly Intrusion
  Report; verify last night's backup.

## 6. System settings (Admin → Settings)
Grouped, typed keys incl.: `auth.otp_enabled`, `auth.otp_ttl_minutes`, `auth.lockout_attempts` (3),
`auth.lockout_hours` (24), `auth.session_idle_minutes`, `security.device_enforcement`,
`security.ids_enabled`, `security.auto_block_threshold`, `security.auto_block_window_minutes`,
`security.ip_block_hours`, `leave.monthly_vl_accrual` (1.25), `leave.monthly_sl_accrual` (1.25).
Every change is audited (old → new).

## 7. Leave administration
- **Leave Types** (HR/Admin): edit max days, deductible/credit source, filing deadline,
  document rules, detail fields, approval flow; create custom types (FR "Other Leave").
- **Holidays**: maintain the holiday calendar — it drives working-day computation.
- **Balances**: HR may adjust with a mandatory remark (creates an `adjustment` ledger row).
- Monthly accrual runs automatically (scheduler); `php artisan leave:accrue` re-runs safely.

## 8. Backups & restore
**Taking one.** `php artisan lms:backup`, the nightly scheduled task, or the **Backups** page in
the browser. The archive holds the whole database and every uploaded document. Keep 30 daily and
12 monthly copies off this machine — a backup that burns with the server was never a backup.

**Putting one back.** From a terminal on the server:

```
php artisan lms:restore --latest          # newest archive in storage/app/backups
php artisan lms:restore path/to/lms_20260915_010000.zip
```

| Option | What it does |
|---|---|
| *(none)* | Names the archive and asks you to confirm before replacing anything. |
| `--latest` | Uses the newest archive instead of naming one. |
| `--force` | Skips the question. For scripts, not for fingers. |
| `--documents-only` | Puts the uploaded documents back and leaves the database alone. |

It is a command and not a button on purpose: restoring discards every leave application filed
since that archive was taken. Before you run it, tell people to stop using the system and stop
the queue worker; afterwards, check the employee count and the newest application on the
dashboard before letting anyone back in. If a statement fails part-way the command says which
one — the archive is untouched, so fix the cause and run it again.

**Once a quarter**, restore the newest archive onto a scratch database and confirm the counts
match. An untested backup is a promise, not a safeguard. Write the date in the maintenance log.

## 8.1 When someone cannot receive their OTP
The mail relay is down, or the mailbox on their record is one nobody opens. The OTP screen now
says delivery failed instead of leaving them waiting. To let that one person in:

1. **Users → (row menu) → One-time sign-in code**.
2. The code appears once, in the banner. Read it to them in person — not by e-mail, not in a chat
   message, which would hand the second factor to whoever is reading that channel.
3. It behaves like any other code: works once, expires with the usual window, and retires any
   code already outstanding for them.
4. It is recorded as `otp_bypass_issued` against your name, with the time and your IP.

Reach for this instead of the OTP switch in System Settings — that one turns the second factor
off for every account in the LGU. Then fix the mail configuration (`MAIL_*` in `.env`, then
`php artisan queue:work`) so the next person does not need you.

## 9. Maintenance
- Update vendored UI assets: replace files in `public/vendor/*` (ADR-009).
- Log growth: archive `activity_logs`/`intrusion_logs` older than 12 months via
  `php artisan lms:logs:archive` (exports CSV to backups, then prunes — audit_logs are never pruned).
