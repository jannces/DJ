# STRIDE Threat Model

Scope: LAN-deployed Laravel monolith (browser ⇄ Apache/TLS ⇄ app ⇄ MySQL/Redis/SMTP).
Assets: employee PII & salaries, leave records, credentials, audit integrity, service availability.

## Trust boundaries
1. **TB1** Browser → Apache (untrusted user input enters here)
2. **TB2** App → MySQL/Redis (localhost, credentialed)
3. **TB3** App → SMTP relay (LAN mail server)
4. **TB4** Admin workstation → server console (out of band)

## S — Spoofing
| Threat | Mitigation (implemented) |
|---|---|
| Credential guessing / stolen password | Strong password policy; bcrypt; login throttling; 3-strike 24 h lockout (FR-A8); email OTP second factor |
| Session hijack / fixation | Session ID regeneration on login; HttpOnly+Secure+SameSite cookies; DB sessions with idle timeout; TLS |
| Fake identity at registration | Unique username/email validation; accounts are created only by admins/HR (no self-registration) |
| Rogue machine on LAN | Authorized-device allow-list middleware; unauthorized devices get a block page + intrusion event |
| OTP interception/replay | 5-min TTL, single-use, hashed at rest, 5-attempt cap, invalidated on reissue |

## T — Tampering
| Threat | Mitigation |
|---|---|
| SQL injection | Eloquent/PDO prepared statements; IDS signatures as defense-in-depth; Form Request validation |
| Request forgery (CSRF) | Laravel CSRF on all state-changing routes; failures logged as intrusion events |
| Parameter tampering (approve own leave, change salary) | Server-side authorization on every action (policy + workflow state machine); frozen snapshots on filed forms |
| Data-in-transit modification | TLS on TB1; DB/Redis bound to localhost (TB2) |
| Upload of malicious files | Mime/extension/size whitelist, hashed storage names, download via controller with `Content-Disposition` |

## R — Repudiation
| Threat | Mitigation |
|---|---|
| "I never approved that leave" | Append-only `approvals` + `audit_logs` with actor, role, timestamp, IP, user-agent, digital signature snapshot |
| Log tampering | No update/delete code paths; DB user for the app can be denied DELETE on audit tables in production (documented in Deployment.md); daily backups |
| Untraceable admin actions | Every RBAC/user/device/setting mutation audited with old/new values |

## I — Information Disclosure
| Threat | Mitigation |
|---|---|
| Horizontal access (view another employee's leave) | Policies scope queries by owner/department; RBAC middleware |
| Salary/PII leakage | Field-level permission `employees.view-salary`; hidden model attributes; exports respect permissions |
| Secrets in repo | `.env` git-ignored; config via env vars |
| Verbose errors | `APP_DEBUG=false` in production; custom 403/404/419/429/500 pages |
| Password exposure | Bcrypt only; redaction in audit logger; never returned by APIs |

## D — Denial of Service
| Threat | Mitigation |
|---|---|
| Login/OTP flood | Route throttling; lockout; OTP issue rate cap |
| Request flood from LAN host | Sliding-window rate anomaly detection → automatic IP block (24 h, admin-managed) |
| Mail bomb via notifications | Queued mail through Redis worker; per-event dedup |
| Heavy report queries | Pagination, indexes, date-bounded report queries |

## E — Elevation of Privilege
| Threat | Mitigation |
|---|---|
| Forced browsing to admin routes | `permission:` middleware on every privileged route; 403 page; `privilege` intrusion event on repeated attempts |
| Mass assignment | `$fillable` whitelists; DTO/Form Request layer |
| Role self-escalation | RBAC mutations require `rbac.manage`; users cannot edit their own roles (guard in controller + policy); audit trail |
| IDOR on documents | Download controller checks ownership or reviewer permission before streaming |

## Residual risks (accepted, documented)
- Physical access to the server bypasses application controls → mitigate with OS hardening (Deployment.md §hardening).
- Self-signed TLS requires certificate distribution to LAN clients; otherwise users may habituate to warnings.
- Email OTP depends on LAN SMTP availability; fallback: admin can temporarily disable OTP via system settings (audited).
