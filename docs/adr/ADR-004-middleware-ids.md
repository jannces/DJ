# ADR-004: IDS as HTTP middleware
**Status:** Accepted
**Context:** Requirement: detect SQLi/XSS/traversal/CSRF/rate anomalies with real-time alerts, on a single host without extra infrastructure (no Snort/Suricata admin available).
**Decision:** Application-layer IDS middleware: curated regex signatures over URI/params, sliding-window request-rate anomaly, CSRF/403 capture via exception handler; events → intrusion_logs → auto IP block + queued admin email + dashboard polling.
**Consequences:** + Zero extra services, alerts are contextual (user, route). − Application-layer only; network-layer IDS remains a documented recommendation.
