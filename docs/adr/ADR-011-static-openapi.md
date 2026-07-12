# ADR-011: Hand-maintained OpenAPI spec + bundled Swagger UI
**Status:** Accepted
**Context:** Swagger docs required, offline. Annotation generators (l5-swagger) add heavy build steps.
**Decision:** Maintain public/openapi.yaml by hand next to the API code; serve Swagger UI from vendored assets at /api/documentation.
**Consequences:** + Zero codegen, offline. − Spec discipline needed → API tests assert documented routes exist.
