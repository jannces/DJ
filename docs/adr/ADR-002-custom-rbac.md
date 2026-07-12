# ADR-002: Custom database-driven RBAC
**Status:** Accepted
**Context:** Manuscript mandates tables `roles`/`permissions`, role inheritance, per-user grants, and forbids hardcoded permissions. spatie/laravel-permission has no native role inheritance and imposes its own table names.
**Decision:** Implement RBAC in-house: roles (self-referencing parent for inheritance), permissions, role_user, permission_role, permission_user (allow/deny). Resolution cached; Gate::before delegates to RbacService.
**Consequences:** + Exact fit to requirements, thesis-demonstrable design. − We own the tests for resolution logic (covered in tests/Feature/RbacTest).
