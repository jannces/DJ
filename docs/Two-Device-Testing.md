# Testing the Workflow Across Two Devices

Goal: **HR on one laptop, Employee on another**, both signed into the same system, so
you can watch a leave request move through the approval workflow live — which is far
easier to demo than logging out and back in on one machine.

One machine is the **server** (XAMPP + the project). Every other laptop is just a
browser. The server itself can also run one of the browser sessions.

---

## Step 1 — Make the system reachable

Follow **`docs/LAN-Access.md`**, then confirm with:

```
php artisan lms:lan
```

Every laptop should be able to open the URL it prints. Don't continue until that works —
everything below assumes it does.

---

## Step 2 — Make notifications actually fire ⚠️

**This is the step that decides whether the test works at all.**

Leave notifications (`LeaveStatusNotification`) and the login OTP email (`OtpCodeMail`)
both implement `ShouldQueue`. With the shipped `QUEUE_CONNECTION=database` they are
written to the `jobs` table and **wait there** — nothing sends them. The symptom:

- HR approves a request, and the employee's laptop shows **nothing, ever**.
- Worse, **you cannot even log in** — the OTP code is never written to
  `storage/logs/laravel.log`, so you have no code to type.

Pick one fix:

### Option A — Run everything instantly (simplest for testing)

In `.env` on the server:

```
QUEUE_CONNECTION=sync
```

Then `php artisan config:clear`. Jobs now run immediately, in the same request. Perfect
for a demo. Revert to `database` for real deployment, where a slow mail server would
otherwise make users wait.

### Option B — Run a queue worker (matches production)

Leave `QUEUE_CONNECTION=database` and open a **second Command Prompt**:

```
php artisan queue:work --tries=3
```

Keep it open alongside the server window. This is what `docs/Deployment.md` §4 installs
as a service for real use.

> Check it's working: `php artisan queue:work --once` should print a processed job after
> you submit a leave request. If the `jobs` table keeps growing, nothing is consuming it.

---

## Step 3 — Deal with the OTP

Every login emails a 6-digit code. With `MAIL_MAILER=log` that code goes to
`storage\logs\laravel.log` **on the server** — so signing in on the employee laptop means
walking to the server to read the code. That gets old fast.

For testing, turn it off: sign in as Super Admin, go to
**Administration → System Settings**, set **`auth.otp_enabled`** to OFF.

> Turn it back ON before any real use or defense demo where security is the point — it's
> one of the system's headline controls.

Alternatively keep it on and use a LAN mail catcher, or read the log with:

```
php artisan pail
```

---

## Step 4 — Authorize both laptops (only if enforcement is ON)

If **`security.device_enforcement`** is ON, every device needs registering or it gets a
403 "Unauthorized device" page:

```
php artisan lms:device:add 192.168.1.25 "HR Laptop"
php artisan lms:device:add 192.168.1.31 "Employee Laptop"
```

Run `ipconfig` on each laptop to get its address. `php artisan lms:lan` lists what's
currently registered.

---

## Step 5 — Sign in as different roles

Use **different browsers or a private/incognito window** if two roles share one machine —
sessions are per-browser, so signing in as HR in the same browser logs the employee out.
Across two separate laptops this is not an issue.

Demo accounts (from `DemoDataSeeder`, password `Alicia@2026Demo!`):

| Laptop | Role | Email |
|--------|------|-------|
| A | Employee | `employee@alicia.gov.ph` |
| B | Department Head | `depthead@alicia.gov.ph` |
| B | HR Officer | `hr@alicia.gov.ph` |
| B | Municipal Mayor | `mayor@alicia.gov.ph` |

Each account is forced to change its password on first login — do that once per account
before the demo so it doesn't interrupt you.

---

## Step 6 — Run the workflow and watch it move

The topbar bell polls every 15 seconds, so a decision made on one laptop appears on the
other **without refreshing** — the badge count updates and a toast pops up.

1. **Laptop A (Employee):** Apply for Leave → Vacation Leave → pick dates → Submit.
2. **Laptop B (Dept Head):** Department Reviews → the request is listed → Recommend.
   Within ~15s Laptop A's bell shows a new notification.
3. **Laptop B (HR):** HR Validation → certify credits → Endorse.
4. **Laptop B (Mayor):** Final Approval → Approve.
5. **Laptop A:** the toast announces the approval, and **My Leave Requests** shows the
   updated status and recalculated leave credits.

Leave both screens visible side by side — that's the demo.

> The page body itself does not live-update; only the bell does. Open the request page and
> refresh (F5) to see the status change in the table.

### Tuning the polling speed

15 seconds is a long pause during a live demo. Lower it in
**Administration → System Settings** → `general.notifications_poll_seconds` (e.g. `5`),
or seed a different default. Don't set it too low on a real deployment — every signed-in
browser hits the server on that interval.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Bell never updates on the other laptop | Queued jobs are not running | Step 2 |
| No OTP code in `laravel.log`, cannot log in at all | Same — the mail job is queued | Step 2 |
| 403 "Unauthorized device" | Device enforcement, laptop not registered | Step 4 |
| Login bounces back to the login page | `SESSION_SECURE_COOKIE=true` over HTTP | `docs/LAN-Access.md` step 2 |
| Signing in as HR logged out the employee | Same browser, one session | Use separate laptops or an incognito window |
| Bell updates, but the list still shows the old status | Only the bell polls | Refresh the page |
| Employee sees no request to approve | Employee's department has no Dept Head assigned | Check the employee's department in **Users** |

Run `php artisan lms:lan` on the server first — it catches the configuration blockers
before you start clicking.
