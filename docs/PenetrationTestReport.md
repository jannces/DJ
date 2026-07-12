# Penetration Test Report

**System:** Cybersecurity Integrated Digital Leave Management System (LGU Alicia)
**Engagement type:** Grey-box application security assessment (authorized, self-assessment)
**Scope:** The Laravel application on the LAN (web + `/api/v1`). Out of scope: OS, Apache, MySQL host hardening (covered by Deployment.md checklist).
**Method:** OWASP Testing Guide v4 + STRIDE (ThreatModel.md). Findings verified by automated tests (`tests/Feature/Security`, `tests/Feature/Auth`, `tests/Feature/Rbac`) and manual probing against a running instance.
**Overall result:** No High or Critical findings open. All identified issues are mitigated by implemented controls; residual items are documented and accepted.

## 1. Summary of tests performed

| # | Test | Technique | Result | Evidence |
|---|------|-----------|--------|----------|
| P1 | SQL Injection | `1' OR 1=1 --`, `UNION SELECT`, `SLEEP()` in query/body | **Blocked (400) + logged** | IDS signature `sqli`; `IntrusionDetectionTest::test_it_detects_sql_injection_attempts`. ORM uses prepared statements throughout. |
| P2 | Cross-Site Scripting | `<script>`, `onerror=`, `javascript:` payloads in inputs and reflected fields | **Blocked + Blade auto-escaping** | IDS `xss` signature; no `{!! !!}` on user data; `test_it_detects_xss_attempts`. |
| P3 | Directory traversal / LFI | `../../etc/passwd`, encoded `%2e%2e`, null byte | **Blocked (400) + logged** | IDS `traversal`; document downloads stream by DB id, never by user path; `test_it_detects_directory_traversal`. |
| P4 | CSRF | State-changing POST without token | **419 rejected + logged as intrusion** | Laravel VerifyCsrfToken; exception handler records `csrf` events. |
| P5 | Brute force / credential stuffing | Repeated bad logins | **Account blocked after 3 attempts / 24 h + per-IP throttle** | `LoginTest::test_three_failed_attempts_block_the_account_for_24_hours`; RateLimiter on login. |
| P6 | Authentication bypass / MFA | Reach dashboard without OTP; replay/expired OTP | **Blocked** — `otp.verified` gate; single-use, 5-min, hashed OTP | `LoginTest::test_otp_gate_blocks_dashboard_until_verified`; `OtpTest`. |
| P7 | Broken access control (vertical) | Employee requests `/users`, `/security`, etc. | **403 + `privilege` intrusion log** | `RbacTest::test_permission_middleware_blocks_unauthorized_and_logs_it`. |
| P8 | Broken access control (horizontal / IDOR) | View another employee's leave/documents | **403** — ownership/department/permission checks in controller + download guard | `LeaveRequestController::authorizeView`. |
| P9 | Privilege escalation | User edits own roles; mass-assignment of `status`/roles | **Blocked** — self-role guard; `$fillable` whitelists; Form Requests | `UserController::assignRoles` self-guard. |
| P10 | Sensitive data exposure | Salary visibility; secrets; error verbosity | **Field-perm `employees.view-salary`; `.env` ignored; `APP_DEBUG=false`; audit redaction** | Security.md §6. |
| P11 | Session security | Fixation, idle timeout, cookie flags | **Regenerate on login; 30-min idle logout; HttpOnly/Secure/SameSite** | `SessionTimeout` middleware; `session()->regenerate()`. |
| P12 | Rate / DoS | Request flood from one IP | **Rate anomaly → auto IP block (24 h)** | `IntrusionDetectionTest::test_repeated_events_auto_block_the_ip`. |
| P13 | Unauthorized device | Access from unregistered IP (enforcement on) | **403 device page + `device` intrusion log** | `PasswordAndDeviceTest::test_device_enforcement_blocks_unregistered_devices`. |
| P14 | Security headers | Response header audit | **CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, HSTS (TLS)** | `SecurityHeaders` middleware. |
| P15 | File upload abuse | Non-whitelisted/oversized/executable upload | **Rejected** — mime+ext+5 MB validation, hashed storage, controller-gated download | `LeaveRequestController` validation rules. |
| P16 | User enumeration | Forgot-password / login messages | **Uniform responses** | `PasswordAndDeviceTest::test_forgot_password_never_reveals_whether_the_email_exists`. |

## 2. Findings

### Informational / accepted residual risks
- **I-1 Self-signed TLS** — LAN certificate is self-signed; distribute the CA to clients to avoid warning habituation (Deployment.md §3).
- **I-2 LAN IP spoofing** — device allow-list is IP-based; a host spoofing an authorized static IP bypasses it. Mitigate at the switch (port security / DHCP snooping). Documented in ADR-006 / ThreatModel.md.
- **I-3 Application-layer IDS only** — no network IDS; recommended as defense-in-depth (ADR-004).
- **I-4 Physical server access** — bypasses app controls; mitigate with OS hardening (Deployment.md §7).

No remediation actions are outstanding; all functional controls are implemented and test-verified.

## 3. Retest / evidence reproduction
```bash
php artisan test tests/Feature/Security tests/Feature/Auth tests/Feature/Rbac
```
All security-relevant tests pass (see Testing.md). Manual probes (curl payloads P1–P3) return HTTP 400 and create `intrusion_logs` rows visible on the Security Dashboard.

## 4. Conclusion
The application implements layered, test-verified controls covering the full STRIDE matrix and the OWASP Top 10 categories relevant to its attack surface. It is fit for LAN deployment at the LGU subject to the host-hardening checklist in Deployment.md.
