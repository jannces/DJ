# Security Test Matrix — Evidence

This folder is the evidence pack for the six security acceptance scenarios in the
thesis test plan. Each scenario is executed **50 times** by an automated PHPUnit
suite that drives the system's own live controls (IDS middleware, login-security
service, RBAC permission middleware). All 300 trials pass.

| # | Scenario | Security control verified | Trials | Result |
|---|----------|---------------------------|--------|--------|
| 1 | Detected Vulnerabilities (endpoint sweep) | Input validation · RBAC · Authentication · Secure config | 50/50 | PASS |
| 2 | Unauthorized Access Attempt | OTP gate · RBAC · Access logging | 50/50 | PASS |
| 3 | Blocked Unauthorized Login Attempts | Login-attempt blocking · IP/source monitoring | 50/50 | PASS |
| 4 | SQL Injection Attempt | Input validation · IDS signatures · Parameterized queries | 50/50 | PASS |
| 5 | Brute Force Login Attempt | Login blocking · OTP · Intrusion logging | 50/50 | PASS |
| 6 | Intrusion Alerts Generated | Real-time alerts · Audit logging | 50/50 | PASS |

## What is in this folder

| File | What it proves |
|------|----------------|
| `report.html` | Printable evidence report (the source of the screenshots). |
| `images/report-summary.png`, `images/report-full.png` | Figures for the appendix. |
| `security-evidence.sqlite` | **Database evidence.** One row per trial in `security_test_runs` (300 rows), the per-scenario tallies in `scenario_summary`, and real application log rows captured during the run in `evidence_samples`. |
| `runs.csv` | The same 300-trial ledger as a spreadsheet. |
| `summary.json` | Machine-readable per-scenario pass/fail counts. |
| `samples/*.json` | Representative rows pulled live from the application's own `intrusion_logs` and `failed_logins` tables while the attacks ran. |

### Querying the database evidence

```sql
-- Per-scenario result
SELECT * FROM scenario_summary;

-- Every trial of one scenario, with its measured detail
SELECT iteration, result, detail_json
FROM   security_test_runs
WHERE  scenario = 'SQL Injection Attempt'
ORDER  BY iteration;

-- The intrusion/failed-login rows the system actually wrote during the attacks
SELECT * FROM evidence_samples;
```

```bash
sqlite3 docs/security-evidence/security-evidence.sqlite "SELECT * FROM scenario_summary;"
```

## How to reproduce

From the project root:

```bash
# 1. Run the matrix (50 iterations per scenario; override with the env var)
SECURITY_MATRIX_ITERATIONS=50 php artisan test --filter=SecurityTestMatrixTest

# 2. Build the database, CSV and HTML report from the run's raw evidence
php scripts/security-evidence-report.php

# 3. Render the report to PNG figures (uses the bundled Chromium)
node scripts/security-evidence-screenshots.cjs
```

Steps 1–2 regenerate everything into `storage/app/security-evidence/`
(git-ignored runtime output). The copy in this folder is a committed snapshot of
one such run so the evidence travels with the thesis.

## Scope and honesty note

This is a **defensive self-assessment** run in-process against the project's own
application, not an external engagement. Where the thesis plan names an external
tool (OWASP ZAP, SQLMap, Hydra), the suite reproduces the *check that tool
performs* against the live controls:

- **Scenario 1** sweeps every role-restricted endpoint anonymously and as an
  employee, the way a ZAP baseline scan probes access control; a "high/critical
  finding" is a restricted page served or a 5xx error. None occurred.
- **Scenario 4** proves injection fails at **both** layers: the IDS blocks and
  logs the classic payload shapes, and *every* payload — including quote-only
  tautologies no signature need match — is run through a real parameterized
  query and returns zero rows, showing it was treated as a literal, never SQL.

For an external, network-level scan (ZAP/SQLMap against a running HTTPS
instance), see `docs/PenetrationTestReport.md`.
