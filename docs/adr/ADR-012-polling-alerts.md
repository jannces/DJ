# ADR-012: AJAX polling for real-time alerts
**Status:** Accepted
**Context:** "Real-time" intrusion alerts on XAMPP/Apache (no websocket daemon, mod_php).
**Decision:** 15-second AJAX polling of /api/v1/security/alerts (unseen intrusion count + latest events) drives navbar badge + SweetAlert toasts; high-severity events also emailed via queue.
**Consequences:** + Works on stock XAMPP. − Up to 15 s latency (acceptable for the use case; interval configurable).
