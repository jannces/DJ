# ADR-005: MySQL production, SQLite dev/CI
**Status:** Accepted
**Context:** XAMPP ships MariaDB/MySQL; CI container has no MySQL daemon.
**Decision:** Portable migrations (no engine-specific SQL); .env defaults to MySQL for deployment, phpunit + dev container use SQLite in-memory/file.
**Consequences:** + Fast tests, faithful deploy. − Engine-specific behaviors (CHECK enforcement) also guarded in application code.
