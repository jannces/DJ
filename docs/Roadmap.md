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
- ✅ Laravel 12 skeleton + offline vendor assets committed
- (updated as phases complete — see git history for the authoritative trail)
