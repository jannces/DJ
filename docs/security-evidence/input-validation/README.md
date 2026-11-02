# Input Validation — Evidence

Automated backing for the functional test-plan row:

> **Input validation** — Empty, oversized, special-character, script, and
> invalid-format input across login and leave-form fields · **50 trials** ·
> Input rejected; no unhandled error; no script executed.

`tests/Feature/Security/InputValidationTest.php` fires all five categories at
the real `/login` and `/leave` endpoints **50 times each** (250 trials total),
asserting the three criteria on every trial. All pass.

| # | Category | What it verifies | Trials | Result |
|---|----------|------------------|--------|--------|
| 1 | Empty Input Rejected | Required-field validation (login + leave form) | 50/50 | PASS |
| 2 | Oversized Input Rejected | Max-length (identifier 255, purpose 1000, signature 150) | 50/50 | PASS |
| 3 | Special-Character Input Accepted | Ordinary punctuation accepted, not falsely blocked | 50/50 | PASS |
| 4 | Script Input Blocked (XSS) | IDS blocks `<script>`/handlers (400) + logs xss; no raw reflection | 50/50 | PASS |
| 5 | Invalid-Format Input Rejected | Bad dates, end-before-start, disallowed file type | 50/50 | PASS |

## The three criteria, per trial

- **Input rejected** — malformed/empty/oversized/invalid-format raises a
  validation error; script payloads are blocked by the IDS (HTTP 400). Nothing
  is accepted.
- **No unhandled error** — every response is a clean validation redirect or
  block page; no trial produced a 500.
- **No script executed** — script payloads are stopped before storage and the
  block page never reflects the raw payload, so nothing can run.

## Note on category 3 (deliberate)

Ordinary punctuation in a free-text field (an apostrophe, a hyphen, `--`) is
**accepted, not rejected** — rejecting legitimate text would be a usability bug.
The IDS is tuned so `"Family emergency -- urgent"` passes while `<script>` and
`UNION SELECT` are blocked. State the criterion precisely in the manuscript:
*malformed* input is rejected; *valid* input (including punctuation) is accepted.

## Files

| File | What it is |
|------|------------|
| `report.html`, `images/*.png` | The evidence report and its screenshots. |
| `input-validation-evidence.sqlite` | Queryable DB: `security_test_runs` (250 trials), `scenario_summary`, `evidence_samples` (captured xss `intrusion_logs` rows). |
| `runs.csv` | The 250-trial ledger as a spreadsheet. |
| `samples/*.json` | xss intrusion rows captured live during the script trials. |

## Reproduce

```bash
INPUT_VALIDATION_ITERATIONS=50 php artisan test --filter=InputValidationTest
php scripts/security-evidence-report.php input-validation-evidence
node scripts/security-evidence-screenshots.cjs input-validation-evidence
```
