# ADR-006: Authorized devices keyed by IP + hostname registration
**Status:** Accepted
**Context:** "Only authorized devices can access the system" on a LAN with DHCP reservations managed by the LGU IT office.
**Decision:** authorized_devices stores IP, hostname, status, last_active. Middleware matches request IP against active devices (cacheable); unauthorized → branded block page + intrusion event. Enforcement toggleable via system setting (bootstrap problem: first device).
**Consequences:** + Simple, auditable, matches LGU static-IP practice. − IP spoofing on the LAN is out of app scope (documented residual risk; mitigate with switch port security).
