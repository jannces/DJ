# ADR-009: Vendored frontend assets (no CDN)
**Status:** Accepted
**Context:** LAN-only deployment may have zero internet.
**Decision:** Bootstrap 5, Bootstrap Icons, Chart.js, SweetAlert2, Swagger UI copied into public/vendor at build time and committed.
**Consequences:** + Works fully offline. − Manual bump procedure documented in AdminGuide.
