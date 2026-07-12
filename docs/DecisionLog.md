# Decision Log

Architecture Decision Records live in `docs/adr/`. Index:

| ADR | Decision | Status |
|-----|----------|--------|
| [ADR-001](adr/ADR-001-layered-monolith.md) | Layered Laravel monolith, not microservices | Accepted |
| [ADR-002](adr/ADR-002-custom-rbac.md) | Custom DB-driven RBAC (with inheritance) instead of spatie/laravel-permission | Accepted |
| [ADR-003](adr/ADR-003-email-otp.md) | Email OTP (hashed, 5-min TTL) as second factor | Accepted |
| [ADR-004](adr/ADR-004-middleware-ids.md) | Signature + anomaly IDS as HTTP middleware, not a separate service | Accepted |
| [ADR-005](adr/ADR-005-mysql-prod-sqlite-dev.md) | MySQL in production, SQLite for dev/CI tests | Accepted |
| [ADR-006](adr/ADR-006-ip-device-allowlist.md) | Device authorization keyed on IP+hostname registration | Accepted |
| [ADR-007](adr/ADR-007-json-leave-type-rules.md) | Leave-type rules (detail schema, documents, workflow) stored as JSON policy on `leave_types` | Accepted |
| [ADR-008](adr/ADR-008-append-only-audit.md) | Append-only audit/ledger tables | Accepted |
| [ADR-009](adr/ADR-009-offline-assets.md) | All frontend assets vendored locally (no CDN) | Accepted |
| [ADR-010](adr/ADR-010-exports.md) | dompdf for PDF, maatwebsite/excel for XLSX/CSV | Accepted |
| [ADR-011](adr/ADR-011-static-openapi.md) | Hand-maintained OpenAPI 3 spec + bundled Swagger UI | Accepted |
| [ADR-012](adr/ADR-012-polling-alerts.md) | Real-time alerts via short-interval AJAX polling, not WebSockets | Accepted |
