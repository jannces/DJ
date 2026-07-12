# Requirements Specification

**Project:** Cybersecurity Integrated Digital Leave Management System with Real-Time Intrusion Alerts
**Client:** Local Government Unit (LGU) of Alicia
**Document status:** Baseline v1.0 (Phase 1 – Requirements Analysis)

The master manuscript/prompt is the primary source of truth. Where the manuscript is silent,
requirements were completed using Philippine Civil Service Commission (CSC) rules
(Omnibus Rules on Leave, CSC Form No. 6 Revised 2020) and industry best practice.

---

## 1. Stakeholders & Roles

| Role | Description |
|------|-------------|
| Super Admin | Owns the platform. Full access, including role/permission administration and system settings. |
| System Administrator | Operates the platform: user accounts, authorized devices, security dashboard, intrusion alerts, audit logs. |
| HR | Human Resources officer: employees, departments, leave balances, leave validation/certification, reports. |
| Department Head | Reviews and recommends leave requests of employees in their department. |
| Employee | Files leave applications, tracks balances/status/history. |
| Municipal Mayor (approver seat) | Final approval authority (modelled as a permission `leave.final-approve`, by default granted to Super Admin and assignable to a Mayor account). |

## 2. Functional Requirements

### 2.1 Authentication (FR-A)
- FR-A1 Login with email/username + password.
- FR-A2 Email OTP as second factor after successful password check (6-digit, 5-minute expiry, single use, max 5 verify attempts).
- FR-A3 Logout (session invalidation + token regeneration).
- FR-A4 Remember-me (Laravel remember token; OTP still required at login).
- FR-A5 Forgot password → email reset link (60-min expiry) → strong password rules enforced.
- FR-A6 Strong password policy: min 12 chars, upper+lower+digit+symbol, not compromised (Laravel `Password::default` extended), not equal to last password.
- FR-A7 Session expiration: idle timeout (configurable, default 30 min) + absolute session lifetime.
- FR-A8 Account lockout: **3 failed login attempts → account blocked for 24 hours**, with reason, IP, browser/user-agent, timestamp logged; admin can unblock manually.
- FR-A9 Device verification: requests are only served to devices whose IP is registered and active in `authorized_devices` (toggleable via system settings; login page shows an "unauthorized device" page otherwise).
- FR-A10 Session fixation prevention (`session()->regenerate()` on login), secure/HttpOnly/SameSite cookies.

### 2.2 RBAC (FR-R)
- FR-R1 Roles: Super Admin, System Administrator, HR, Department Head, Employee (seeded, editable).
- FR-R2 Permissions are database records; **no hardcoded permission checks** — middleware/Gate resolve dynamically.
- FR-R3 Role ↔ permission assignment UI; user ↔ role assignment UI; direct user-level permission grants.
- FR-R4 Role inheritance (a role may inherit its parent's permissions transitively).
- FR-R5 Menu visibility driven by permissions.
- FR-R6 403 "Unauthorized access" page; unauthorized attempts are logged as intrusion events (privilege escalation probes).

### 2.3 Employee & Organization Management (FR-E)
- FR-E1 CRUD employees (profile: employee number, name, gender, civil status, birth date, contact, address, salary, department, position, employment status, date hired).
- FR-E2 CRUD departments and positions.
- FR-E3 Archive / restore / delete users (soft-delete + archives register).
- FR-E4 View employee leave history and audit history.

### 2.4 Leave Management (FR-L)
- FR-L1 Digital CSC Form No. 6 (Revised 2020): employee info (office/department, name, date filed, position, salary), application details (leave type, number of working days, inclusive dates, details of leave, purpose/commutation, applicant digital signature), supporting document uploads.
- FR-L2 Seeded CSC leave types with rules (see Database.md §leave_types): Vacation, Mandatory/Forced (5 d/yr), Sick, Maternity (105 d), Paternity (7 d), Special Privilege (3 d), Solo Parent (7 d), Study (≤6 mo), 10-Day VAWC, Rehabilitation (≤6 mo), Special Leave Benefits for Women (≤2 mo), Special Emergency/Calamity (≤5 d), Monetization, Terminal, Adoption + admin-defined custom types.
- FR-L3 Leave Type Management module: name, code, max days, deductible flag + credit source (VL/SL/none), required document rules, detail-field schema, filing deadline (days before start), approval workflow steps, expiration/annual reset policy, active flag.
- FR-L4 Details-of-leave conditional fields (VL: within PH/abroad+location; SL: hospital/outpatient+illness; Study: master's/BAR/board/other; SLBW: illness/surgery; Monetization: reason etc.).
- FR-L5 Working-day computation excludes weekends and Philippine holidays (holiday table maintained by HR).
- FR-L6 Credit computation: monthly accrual 1.25 VL + 1.25 SL (CSC), earned/less-this-application/balance display, **negative balances are impossible** (guarded at filing and at approval, race-safe via DB transaction + row locks).
- FR-L7 Validation rules (warn/block per manuscript): VL ≥5 days ahead recommended (warning, HR override; HR hard rule 3 days with override), SL >5 days ⇒ medical certificate required (HR rule: >2 days ⇒ required), Maternity ⇒ pregnancy proof, Paternity ⇒ birth/marriage certificate, Solo Parent ⇒ Solo Parent ID, VAWC ⇒ protection order/court order/medical cert/police report, Study ⇒ contract/supporting docs, Calamity ⇒ residence in declared calamity area + government proof, Terminal ⇒ clearance, Adoption ⇒ DSWD documents. Late sick-leave filing captures a late-filing reason.
- FR-L8 Approval workflow: Employee submit → Department Head (approve / reject / return for revision, comments) → HR validation & certification of credits (VL/SL/balance) → Final approval (Mayor seat: approve/disapprove, days with pay / without pay, remarks) → automatic balance deduction → notification. Each actor signs digitally (typed name + stored signature image hash + timestamp).
- FR-L9 Employee actions: apply, view status/balances/history, cancel (before final approval), upload documents, receive notifications (in-app + email queue).
- FR-L10 Monetization requests (letter/reason, HR then Mayor approval), Terminal leave with clearance.
- FR-L11 Printable CSC Form 6 PDF for any application.

### 2.5 System Administration (FR-S)
- FR-S1 Admin dashboard: employee count, leave stats, intrusion stats, online/offline devices, recent activities, system health (DB, queue, storage, cache).
- FR-S2 Authorized device management: register (IP, hostname, description), activate/deactivate, archive, search; last-active tracking; enforcement middleware.
- FR-S3 User account management: create/update/archive/restore/delete, reset password, assign roles/permissions, manual block/unblock, activate/deactivate, view login history, view audit history.
- FR-S4 System settings (key-value, typed): OTP toggle/expiry, lockout threshold/duration, device enforcement toggle, session idle timeout, IDS thresholds, accrual rate, etc.

### 2.6 Intrusion Detection & Security Monitoring (FR-I)
- FR-I1 Detect: failed logins, blocked logins, unauthorized devices/IPs, SQLi patterns, XSS patterns, directory traversal, CSRF failures, privilege-escalation attempts (403s), rapid requests (rate anomalies), repeated failures.
- FR-I2 Record every event in `intrusion_logs` (severity, category, matched signature, route, payload excerpt, IP, user agent, user).
- FR-I3 Real-time alerts: dashboard notification badge (AJAX polling), toast alerts, email alert to admins for high severity (queued).
- FR-I4 Automatic IP blocking after configurable threshold (default: 5 intrusion events / 10 min, or rate-limit breach) with expiry; manual block/unblock.
- FR-I5 Security dashboard: blocked IPs, intrusion count, failed logins, top attackers, recent alerts, most-targeted pages, attacks today/this week/this month, Chart.js charts.

### 2.7 Audit & Activity Logging (FR-G)
- FR-G1 Audit every security-relevant action (login, logout, CRUD, approvals, password/role/permission changes, leave actions, device actions, blocked attempts, intrusions) with user, role, timestamp, IP, browser, action, old value, new value.
- FR-G2 Activity log (page-level user activity trail).

### 2.8 Reports & Search (FR-P)
- FR-P1 Reports: Employee Leave, Department, Monthly, Annual, Leave Balance, Intrusion, Audit, Blocked Login, User Activity.
- FR-P2 Export every report to PDF, Excel (XLSX) and CSV.
- FR-P3 Global search + advanced filters (department, leave type, employee, status, date range, role).

### 2.9 API (FR-X)
- FR-X1 Versioned REST API (`/api/v1`), Sanctum token auth, rate-limited, permission-checked.
- FR-X2 OpenAPI 3 documentation served through bundled Swagger UI at `/api/documentation` (LAN-offline).

## 3. Non-Functional Requirements

| ID | Requirement |
|----|-------------|
| NFR-1 | LAN-only deployment on Apache/XAMPP with HTTPS (self-signed or LGU CA), no internet dependency at runtime (all assets vendored). |
| NFR-2 | Performance: paginated lists, indexed queries, Redis/file cache, queued mail/notifications. |
| NFR-3 | Usability: Bootstrap 5 responsive government UI, dark/light mode, toasts, loading skeletons, accessible labels/contrast. |
| NFR-4 | Maintainability: SOLID, Service layer + Repository pattern, PSR-12, DI via Laravel container. |
| NFR-5 | Security: OWASP ASVS-aligned controls; STRIDE mitigations (see ThreatModel.md). |
| NFR-6 | Reliability: DB transactions on balance updates; backup/restore scripts. |
| NFR-7 | Auditability: immutable append-only audit trail (no update/delete routes). |
| NFR-8 | Quality model: evaluated against ISO/IEC 25010 (see Testing.md). |

## 4. Out of Scope
Payroll computation, biometric integration, SMS OTP (email only per manuscript), internet-facing deployment.

## 5. Acceptance Criteria
Every FR above has at least one automated test (see Testing.md) or a documented manual test case; the demo seed data exercises the full approval workflow end-to-end.
