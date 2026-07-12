# Cybersecurity Integrated Digital Leave Management System
### with Real-Time Intrusion Alerts — Local Government Unit of Alicia

A production-quality, LAN-deployable leave management system for a Philippine LGU,
built on **Laravel 12 / PHP 8.3+ / MySQL** with integrated cybersecurity controls,
real-time intrusion detection, and full CSC Form No. 6 (Revised 2020) support.

> **New here? Read [`RUN_ON_YOUR_PC.md`](RUN_ON_YOUR_PC.md)** for a complete,
> beginner-friendly setup guide.

## Highlights
- **Authentication:** login, **email OTP** MFA, remember-me, forgot/reset, strong-password
  policy, **3-strike / 24-hour account lockout**, session timeout, authorized-device allow-list.
- **RBAC:** fully database-driven roles & permissions with **inheritance** and per-user
  allow/deny overrides — no hardcoded checks; permission-driven menus; 403 logging.
- **Leave management:** digital **CSC Form 6** + printable PDF, **15 CSC leave types**
  (Vacation, Sick, Maternity, Paternity, Solo Parent, Study, VAWC, SLBW, Calamity,
  Monetization, Terminal, Adoption, …) with JSON-configurable policies, automatic
  working-day and **leave-credit computation (never negative, concurrency-safe)**, and a
  Department Head → HR → Mayor **approval workflow** with digital signatures.
- **Security:** middleware **IDS** (SQLi/XSS/traversal signatures, rate anomaly),
  **automatic IP blocking**, security headers, **append-only audit + activity logs**,
  **security dashboard** with charts and **real-time alerts**.
- **Reports:** 9 reports (leave, department, monthly, annual, balances, intrusion, audit,
  blocked-login, activity) each exportable to **PDF / Excel / CSV**; global search.
- **API:** versioned **REST `/api/v1`** (Sanctum), rate-limited, documented with **OpenAPI 3
  + offline Swagger UI** at `/api/documentation`.
- **UI:** Bootstrap 5 government theme, responsive, **dark/light mode**, SweetAlert2 toasts,
  skeleton loaders — all assets vendored for **fully offline LAN** operation.

## Tech stack
Laravel 12 · PHP 8.3+ · MySQL/MariaDB · Redis (optional) · Blade · Bootstrap 5 ·
Chart.js · SweetAlert2 · Sanctum · dompdf · maatwebsite/excel · Apache/XAMPP.

## Quick start (development)
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed                     # schema + roles/permissions/leave types/settings/super admin
php artisan db:seed --class=DemoDataSeeder      # optional demo data
php artisan storage:link
php artisan serve                               # http://127.0.0.1:8000
```
Run the tests: `php artisan test` (**47 passing / 152 assertions**).

## Documentation (`/docs`)
Architecture · Requirements · Database · Security · API · Roadmap · Deployment · Testing ·
ThreatModel (STRIDE) · AdminGuide · UserGuide · DecisionLog + **12 ADRs** · **Diagrams**
(context/DFD/use-case/class/activity/sequence/deployment/network/flowcharts) ·
PenetrationTestReport · ISO25010 · SecurityReport.

## Project layout
```
app/
  Http/{Controllers,Middleware}    # auth, admin, leave, api + security kernel
  Services/{Auth,Rbac,Leave,Security,Reports}   # service layer (SOLID)
  Models/  Notifications/  Exports/  Console/Commands/  Rules/
database/{migrations,factories,seeders,sql}
resources/views/{auth,dashboard,leave,hr,admin,reports,api,partials,layouts}
routes/{web,api,leave,admin,console}.php
deploy/    docs/    tests/{Unit,Feature}
```

## License
Developed as an academic capstone for the Local Government Unit of Alicia.
