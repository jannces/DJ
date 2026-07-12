# Testing Strategy

Runner: PHPUnit via `php artisan test` (SQLite in-memory, `RefreshDatabase`). CI-friendly:
no external services required (mail → array, queue → sync, cache → array in phpunit.xml).

## Test pyramid

| Level | Location | Covers |
|---|---|---|
| Unit | `tests/Unit` | LeaveCreditService math, working-day calculator (weekends/holidays), OTP hashing/expiry, RBAC inheritance resolution, IDS signature matcher, policy engine document rules |
| Feature/Integration | `tests/Feature` | Auth flow (login→OTP→dashboard), lockout after 3 failures + 24 h block + unblock, password reset, session timeout, RBAC route protection + menu visibility, device allow-list, leave application end-to-end (submit → dept head → HR certify → mayor approve → balance deducted → history row → notification), negative-balance prevention incl. concurrent approvals, cancellation, document requirement enforcement, monetization flow, reports render + export headers, API auth + rate limiting + contract vs openapi.yaml, intrusion events (SQLi/XSS/traversal probes create logs; threshold auto-blocks IP), audit log old/new capture |
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
