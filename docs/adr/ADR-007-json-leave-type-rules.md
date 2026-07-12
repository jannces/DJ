# ADR-007: Leave-type policy as JSON columns
**Status:** Accepted
**Context:** 15+ CSC leave types plus admin-defined custom types, each with different detail fields, document requirements, deadlines and workflows. Separate tables per rule dimension would explode the schema.
**Decision:** leave_types carries detail_schema (renderable field spec), required_documents (rule list incl. conditional rules like "medical cert if days>5"), approval_flow (ordered steps). A LeavePolicyEngine interprets these at filing/approval time.
**Consequences:** + New leave types without migrations (FR "Other Leave"). − JSON not FK-verified → validated by a schema validator on save.
