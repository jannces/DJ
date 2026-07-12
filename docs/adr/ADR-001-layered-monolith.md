# ADR-001: Layered Laravel monolith
**Status:** Accepted
**Context:** One LGU, one LAN server (XAMPP/Apache), small ops team, offline environment.
**Decision:** Single Laravel 12 application with Controller → Service → Repository layering; no microservices, no SPA framework.
**Consequences:** + Simple deploy/backup, one security perimeter, easy thesis defense. − Scaling is vertical only (acceptable: ≤ a few hundred LAN users).
