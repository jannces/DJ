# OTP Authentication — Evidence

Covers the functional test-plan row:

> **OTP authentication** — Login with valid credentials but omitted, incorrect,
> expired, or reused OTP · **50 trials** · Access denied in all cases; each
> attempt logged.

Two kinds of evidence, like the other rows:

## 1. Automated — `OtpNegativePathTest` (50× each case)

`tests/Feature/Auth/OtpNegativePathTest.php` runs the four failure modes against
the real `/otp/verify` flow **50 times each** (200 trials). All pass.

| # | Case | What it verifies | Trials | Result |
|---|------|------------------|--------|--------|
| 1 | Omitted OTP Denied | the `otp.verified` gate refuses the dashboard when no code is entered | 50/50 | PASS |
| 2 | Incorrect OTP Rejected | wrong code rejected + `otp_failed` audit row + access still denied | 50/50 | PASS |
| 3 | Expired OTP Rejected | correct code past its 5-min TTL rejected + logged | 50/50 | PASS |
| 4 | Reused OTP Rejected | single-use: an already-consumed code rejected + logged | 50/50 | PASS |

Artifacts: `otp-evidence.sqlite` (200-trial DB), `runs.csv`, `report.html`,
`images/`. Reproduce:

```bash
OTP_ITERATIONS=50 php artisan test --filter=OtpNegativePathTest
php scripts/security-evidence-report.php otp-evidence
node scripts/security-evidence-screenshots.cjs otp-evidence
```

## 2. Live system — real browser against the running app (`system/`)

| File | What it shows |
|------|---------------|
| `01-otp-challenge.png` | Valid login lands on the **"Verify it's you"** OTP screen — access not yet granted. |
| `02-otp-incorrect-rejected.png` | A wrong 6-digit code → **"Invalid or expired code."** |
| `03-otp-omitted-denied.png` | Trying to open `/dashboard` without verifying bounces back to `/otp`. |
| `04-audit-otp-failed.png` | The Audit Log showing **"One-time code rejected"** entries for the user (the `otp_failed` events the attempts created). |

Captured with `scripts/capture-otp-evidence.cjs` (OTP enabled for the employee
flow; disabled only so the admin could open the audit-log page).

## Honest note

Cases 2–4 write an `otp_failed` audit row ("each attempt logged"). The **omitted**
case is denied by the gate (redirect to `/otp`) but writes no row, because no
code was submitted — there is no attempt to log. State the criterion as:
*access denied in all four cases; each submitted code is logged.*
