# ADR-008: Append-only audit and ledger tables
**Status:** Accepted
**Context:** Repudiation controls; thesis requires who/what/when/old/new evidence.
**Decision:** audit_logs, activity_logs, leave_history, approvals expose no update/delete routes or repository methods; corrections are compensating entries (e.g., reversal ledger rows).
**Consequences:** + Trustworthy trail. − Storage growth → monthly archive guidance in AdminGuide.
