# ADR-003: Email OTP second factor
**Status:** Accepted
**Context:** Manuscript requires OTP via email; LAN has an SMTP relay; no SMS gateway.
**Decision:** 6-digit code, SHA-256 hash at rest, 5-minute TTL, single use, 5 verify attempts, re-issue invalidates prior codes. Session gains `otp_verified` only after verification; middleware gates all authenticated routes.
**Consequences:** + No plaintext codes in DB; replay-resistant. − Depends on mail availability → admin toggle `auth.otp_enabled` (audited) as contingency.
