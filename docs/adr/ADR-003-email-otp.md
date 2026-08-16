# ADR-003: Email OTP second factor
**Status:** Accepted
**Context:** Manuscript requires OTP via email; LAN has an SMTP relay; no SMS gateway.
**Decision:** 6-digit code, SHA-256 hash at rest, 5-minute TTL, single use, 5 verify attempts, re-issue invalidates prior codes. Session gains `otp_verified` only after verification; middleware gates all authenticated routes.
**Delivery:** the code is mailed to the account's own `users.email` via the configured mailer (`log` for first setup, SMTP relay/Gmail App Password in real use). Sent inline by default — a queued OTP silently stays in the jobs table without a running worker — with `MAIL_QUEUE_OTP=true` available where `queue:work` is supervised. Transport failures are logged (never the code) and surfaced to the user instead of leaving a blank OTP screen; `php artisan mail:test <email>` verifies the transport.
**Consequences:** + No plaintext codes in DB; replay-resistant. − Depends on mail availability → admin toggle `auth.otp_enabled` (audited) as contingency; login latency now includes the SMTP round-trip unless queued.
