# Testing Strategy

Runner: PHPUnit via `php artisan test` (SQLite in-memory, `RefreshDatabase`). CI-friendly:
no external services required (mail → array, queue → sync, cache → array in phpunit.xml).

## Test pyramid

| Level | Location | Covers |
|---|---|---|
| Unit | `tests/Unit` | LeaveCreditService math (accrual, deduction, negative-balance guard), working-day calculator (weekends/holidays), StrongPassword rule |
| Feature/Integration | `tests/Feature` | Auth flow (login→OTP→dashboard), lockout after 3 failures + 24 h block, blocked account refused, password change/reset, device allow-list, RBAC route protection + menu visibility + deny-overrides-allow, leave application end-to-end (submit → single authorized approval → balance deducted → history row → notification), negative-balance prevention incl. concurrent approvals, medical-certificate policy rule, approval authority (each of Mayor/Vice Mayor/HR alone, Department Head refused, no overturning a decision, no self-approval), employee restrictions (no global search, no CSV, own data only), employee timeline + read-only form preview, reports render + CSV export headers, API auth, intrusion detection (SQLi/XSS/traversal probes logged, threshold auto-blocks IP, loopback never blocked, free-text prose not matched) |
| Security (pentest) | `docs/PenetrationTestReport.md` | Manual + scripted probes executed against a running instance (SQLi, XSS, CSRF, IDOR, brute force, traversal, header audit) with findings and remediations |
| Performance | documented | N+1 audit via eager-loading review, indexed query plans, pagination checks; ab/wrk smoke numbers in the report |

## ISO/IEC 25010 evaluation
`docs/ISO25010.md` scores the eight characteristics (functional suitability, performance
efficiency, compatibility, usability, reliability, security, maintainability, portability)
with the evidence produced by this suite and the survey instrument template used for the
thesis defense.

## Conventions
- One behavior per test; factories provide minimal fixtures; time controlled via `Carbon::setTestNow`.
- Every FR in Requirements.md maps to ≥1 test — traceability matrix at the bottom of ISO25010.md.
- Run: `php artisan test` · filtered: `php artisan test --filter=LeaveWorkflow`.

> This table describes tests that exist. If you add a behaviour here, add the test with
> it — an inaccurate coverage claim is worse than an unmentioned gap.
