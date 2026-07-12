# Security Design

Controls are mapped to STRIDE (full analysis in ThreatModel.md) and OWASP Top 10.

## 1. Authentication controls
- Bcrypt password hashing (Laravel `Hash`), password policy: ≥12 chars, mixed case, number, symbol, uncompromised (`Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()` — the uncompromised check degrades gracefully offline).
- Email OTP second factor: 6-digit code, SHA-256 hashed at rest, 5-min TTL, single-use, 5 verify attempts then invalidated; new login = new code. Session flag `otp_verified` gates all authenticated routes via `EnsureOtpVerified` middleware.
- Account lockout: 3 failed attempts → `users.status=blocked`, `blocked_until = now+24h`, reason/IP/user-agent/timestamp recorded in `failed_logins` + audit log; admin unblock UI. Counter resets on success.
- Login throttling (per IP+identifier, Laravel RateLimiter) *in addition to* the lockout, to blunt distributed guessing.
- Session: database driver, regenerate ID on login (fixation), idle timeout enforced by `SessionTimeout` middleware, secure/HttpOnly/SameSite=Lax cookies, absolute lifetime.
- Remember-me uses Laravel's hashed remember token; OTP is still demanded on each new session.

## 2. Authorization
- DB-driven RBAC with role inheritance and per-user allow/deny overrides. `Gate::before` resolves permissions from cached role graph (cache busted on any RBAC write).
- `permission:` route middleware + `@can` in Blade for menu visibility.
- 403 attempts on privileged routes raise `privilege` intrusion events.

## 3. Input/output safety
- Every write endpoint has a Form Request with strict validation (types, enums, dates, mimes, max sizes).
- Eloquent/PDO prepared statements only; no raw string-built SQL.
- Blade auto-escaping; `{!! !!}` is never used with user data.
- File uploads: whitelist mimes (pdf, jpg, png), 5 MB cap, stored outside web root logic via hashed names on the `local` disk, served through a permission-checked download controller, sha256 recorded.
- Security headers middleware: `Content-Security-Policy` (self-only, no inline scripts except nonce'd bootstrap), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`, HSTS in production.

## 4. Intrusion detection & response
- `IntrusionDetectionMiddleware` scans URI, query, body keys/values against curated signatures:
  - SQLi: quote/comment/UNION/SLEEP/OR 1=1 heuristics
  - XSS: script/event-handler/javascript: URIs
  - Traversal: `../`, encoded variants, `/etc/passwd`, null bytes
- CSRF token failures are captured (exception handler) and logged as `csrf` events.
- Rate anomaly: sliding window per IP (default 120 req/min) → `rate` event.
- Escalation: N events (default 5) within 10 min from one IP → automatic `blocked_ips` row (24 h expiry) + email alert to admins (queued) + dashboard alert.
- Manual IP block/unblock UI; blocked IPs rejected by the first middleware in the pipeline (cheap 403, cached in Redis/file cache 60 s).
- Authorized-device enforcement (LAN allow-list) with unauthorized-device intrusion events.

## 5. Auditability
- `AuditLogger` service writes append-only entries {user, role, action, model, old, new, IP, user-agent, URL}. Model observers cover CRUD on sensitive models; services log domain actions (approvals, blocks, resets).
- `ActivityLogMiddleware` records authenticated page/API hits.
- Reports: Audit Report, Blocked Login Report, User Activity Report with PDF/XLSX/CSV export.

## 6. Secrets & data protection
- All secrets in `.env` (never committed); `.env.example` documents keys.
- APP_KEY encrypts session/cookies; employee salary visible only to roles holding `employees.view-salary`.
- Passwords never logged or exported; audit logger redacts `password`, `token`, `otp` keys.
- Database and mail credentials scoped to LAN host; Redis bound to localhost.

## 7. DoS resistance
- Global + per-route rate limiting (`throttle:api` 60/min per user/IP, login 5/min).
- Automatic IP blocking (above), queue workers absorb mail/notification bursts, pagination everywhere, indexed queries.

## 8. Transport
- Apache VirtualHost with TLS (self-signed cert generation script in `deploy/`); HTTP→HTTPS redirect; HSTS. See Deployment.md.
