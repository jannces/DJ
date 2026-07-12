# REST API (v1)

Base URL: `https://<server>/api/v1` · Auth: **Laravel Sanctum personal access tokens**
(`Authorization: Bearer <token>`) · Content type: JSON · Rate limit: 60 req/min per user/IP
(`429` with `Retry-After`). All endpoints enforce the same RBAC permissions as the web UI.
Interactive docs: **`/api/documentation`** (bundled Swagger UI reading `public/openapi.yaml`).

## Conventions
- Versioned prefix `/api/v1`; breaking changes ⇒ `/api/v2`.
- Errors: `{"message": "...", "errors": {field: [..]}}` with 401/403/404/422/429.
- Pagination: `?page=&per_page=` → Laravel paginator envelope.

## Endpoints

### Auth
| Method | Path | Description |
|---|---|---|
| POST | `/auth/login` | email/username + password → issues OTP challenge `{otp_required: true}` |
| POST | `/auth/otp/verify` | verify code → `{token}` (Sanctum) |
| POST | `/auth/logout` | revoke current token |
| GET | `/auth/me` | current user + roles + permissions |

### Leave
| Method | Path | Permission |
|---|---|---|
| GET | `/leave-types` | `leave.apply` |
| GET | `/leave-requests` (filters: status, type, from, to) | own: `leave.apply`; all: `leave.review` |
| POST | `/leave-requests` | `leave.apply` |
| GET | `/leave-requests/{id}` | owner or reviewer |
| POST | `/leave-requests/{id}/cancel` | owner, pre-final |
| POST | `/leave-requests/{id}/documents` | owner |
| POST | `/leave-requests/{id}/decision` (approve/reject/return + comments, per workflow step) | step permission |
| GET | `/leave-balances` | own; `?user_id=` with `leave.balances.manage` |

### Admin / Security
| Method | Path | Permission |
|---|---|---|
| GET | `/security/alerts` | `security.dashboard` — unseen count + latest intrusion events (polled) |
| GET | `/security/stats` | `security.dashboard` — chart datasets (today/week/month, top attackers, targeted pages) |
| GET/POST | `/blocked-ips`, DELETE `/blocked-ips/{ip}` | `security.blocked-ips` |
| GET/POST/PATCH | `/devices…` (register, activate, deactivate, archive) | `devices.manage` |
| GET | `/users`, POST `/users`, PATCH `/users/{id}`, POST `/users/{id}/unblock` … | `users.manage` |
| GET | `/audit-logs`, `/activity-logs`, `/intrusion-logs` (filters) | `audit.view` / `security.dashboard` |

### Dashboards & reports
| Method | Path | Permission |
|---|---|---|
| GET | `/dashboard/summary` | role-scoped counters for the caller's dashboard |
| GET | `/dashboard/charts/{chart}` | ditto (leave-by-month, leave-by-type, dept-load, intrusions-by-day…) |
| GET | `/reports/{report}?format=pdf|xlsx|csv&filters…` | `reports.generate` (+ report-specific perms) |

The authoritative machine-readable contract is `public/openapi.yaml`; `tests/Feature/ApiDocsTest`
asserts every documented path is routable.
