# Security Report

Consolidated security posture of the Cybersecurity Integrated Digital Leave Management
System for LGU Alicia. Companion documents: Security.md (design), ThreatModel.md (STRIDE),
PenetrationTestReport.md (testing).

## 1. Control coverage vs STRIDE

| STRIDE | Implemented controls | Status |
|--------|----------------------|--------|
| Spoofing | Strong password policy, bcrypt, email OTP (hashed, single-use, 5-min), 3-strike/24h lockout, session regeneration, authorized-device allow-list | ✅ |
| Tampering | Prepared statements (Eloquent), CSRF tokens, TLS, input Form Requests, IDS signatures, file-upload whitelist, frozen form snapshots | ✅ |
| Repudiation | Append-only `audit_logs` + `activity_logs` + `approvals` with user/role/IP/UA/timestamp/old-new; digital signatures | ✅ |
| Information Disclosure | RBAC + policies, field-level salary permission, hidden attributes, `.env` secrets, secure cookies, `APP_DEBUG=false`, audit redaction | ✅ |
| Denial of Service | Login throttle, API rate limit (60/min), request-rate anomaly detection, automatic IP blocking, queue workers, pagination | ✅ |
| Elevation of Privilege | Dynamic RBAC middleware, deny-overrides, self-role guard, `$fillable` whitelists, IDOR download guard, 403 + intrusion logging | ✅ |

## 2. OWASP Top 10 (2021) mapping

| Risk | Mitigation |
|------|-----------|
| A01 Broken Access Control | RBAC middleware + policies; ownership/department checks; self-escalation guard; 403 logged |
| A02 Cryptographic Failures | bcrypt passwords, hashed OTP, TLS, `APP_KEY` cookie/session encryption |
| A03 Injection | ORM prepared statements; IDS SQLi/XSS signatures; validated & escaped I/O |
| A04 Insecure Design | Layered architecture, threat model, ADRs, least-privilege roles |
| A05 Security Misconfiguration | Security headers, debug off in prod, hardening checklist, minimal DB grants |
| A06 Vulnerable Components | Composer/npm audited; assets vendored & version-pinned; update procedure documented |
| A07 Identification & Auth Failures | MFA, lockout, throttling, session timeout, fixation defense |
| A08 Software & Data Integrity | Append-only logs, signed approvals, backups, document hashing |
| A09 Logging & Monitoring Failures | Audit + activity + intrusion logs; real-time alerts; security dashboard |
| A10 SSRF | No server-side fetch of user URLs; LAN-only, egress not required |

## 3. Key security parameters (defaults, admin-configurable)

| Setting | Default |
|---------|---------|
| OTP TTL | 5 minutes, single use, 5 attempts |
| Account lockout | 3 failed attempts → 24 h block |
| Session idle timeout | 30 minutes |
| API rate limit | 60 req/min per user/IP |
| Auto IP block | ≥5 intrusion events / 10 min → 24 h block |
| Request-rate anomaly | >120 req/min per IP |
| Password policy | ≥12 chars, mixed case, digit, symbol |

## 4. Monitoring & response
- **Detect:** IDS middleware + failed-login tracking + CSRF/403 capture.
- **Alert:** dashboard bell (15-s AJAX poll), SweetAlert toasts, queued admin email on high-severity/auto-block.
- **Respond:** automatic IP/account blocking; manual block/unblock; append-only forensic trail; exportable Intrusion/Audit/Blocked-Login reports.
- **Recover:** daily backups + restore command; expired blocks auto-lifted by scheduler.

## 5. Residual risks
See PenetrationTestReport.md §2 (self-signed TLS, LAN IP spoofing, app-layer-only IDS, physical access) — all documented and accepted with compensating operational controls.

## 6. Verification
`php artisan test` → 47 passing tests / 152 assertions, including 17 security/auth/RBAC tests that exercise the controls above.
