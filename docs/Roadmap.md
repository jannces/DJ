# Roadmap / Phase Plan

Build order is fixed; a phase starts only when the previous one is committed.

| Phase | Scope | Exit criteria |
|-------|-------|---------------|
| 1 Requirements | Requirements.md baseline from manuscript | FR/NFR catalog reviewed |
| 2 Architecture | Architecture.md, ADRs, diagrams | Layering + security kernel defined |
| 3 Database | Migrations for 25 tables, models, factories, seeders (roles, permissions, CSC leave types, holidays, demo LGU data) | `php artisan migrate --seed` green |
| 4 Authentication | Login, OTP, logout, remember, forgot/reset, lockout (3×→24 h), session timeout, device verification | Auth feature tests green |
| 5 RBAC | Dynamic permissions, inheritance, middleware, menus, 403 | RBAC tests green |
| 6 Leave Management | CSC Form 6, 15+ leave types, validation rules, credits, approval workflow, monetization/terminal, PDF | Leave tests green; e2e demo flow |
| 7 Security Layer | Security headers, audit/activity logs, rate limiting, blocked IPs, settings | Security tests green |
| 8 Intrusion Detection | IDS middleware, auto-block, alerts, security dashboard | IDS tests green |
| 9 Reports | 9 reports × PDF/XLSX/CSV, global search, 4 dashboards, REST API + Swagger | Report/API tests green |
| 10 Testing | Full suite, pentest report, ISO 25010 evaluation | `php artisan test` green |
| 11 Deployment | XAMPP/Apache/TLS guide, backup/restore, SQL dump, manuals | Fresh-machine install validated |

## Milestone log
- ✅ Phase 1-2 — Laravel 12 skeleton, offline vendor assets, all `/docs` + 12 ADRs + diagrams
- ✅ Phase 3 — 25-table schema, models, factories, seeders (CSC leave types, roles/permissions, demo data)
- ✅ Phase 4-5 — auth (login/OTP/lockout/reset/session/device) + dynamic RBAC (16 tests)
- ✅ Phase 6 — CSC Form 6, 15 leave types, credit computation, approval workflow, PDF (11 tests)
- ✅ Phase 7-8 — security headers/audit/activity + IDS (signatures, auto-block, dashboard) (7 tests)
- ✅ Phase 9 — 9 reports × PDF/XLSX/CSV, search, dashboards, REST API + Swagger (8 tests)
- ✅ Phase 10 — full suite 47 tests/152 assertions passing; pentest + ISO 25010 + security reports; SQL dump
- ⏳ Phase 11 — deployment scripts, backup/restore, beginner run guide
