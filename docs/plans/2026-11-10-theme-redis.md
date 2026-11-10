# Theme Redis replication implementation plan

**Goal:** Replicate committed site themes to a configurable per-site Redis Hash in both hosts, without pretending Redis participates in the PostgreSQL transaction.

**Architecture:** ThemeSnapshotWriter enqueues a coalesced per-site Outbox row on the same trusted connection/transaction. Only the outermost commit triggers best-effort delivery; a shared scheduled command retries pending rows. Lua compares decimal version strings (not doubles), rejects equal-version conflicts and atomically writes the Hash. PostgreSQL remains authoritative.

**Tech Stack:** PHP 8.2+, Illuminate 8 transactions/container/console, PostgreSQL (SQLite isolated tests), Redis Lua.

## Steps

1. Add shared replication contract, Redis publisher, Outbox schema and worker. Test outer/nested rollback, Outbox failure, retry, no-op repairs, redaction and version races.
2. Hook both changed and unchanged ThemeSnapshotWriter paths without changing existing authorization or tenant/site selection.
3. Add shared Laravel provider/config/command; register in both host providers. Add migrations delegating to the shared schema and a conditional one-minute retry schedule in both hosts. Concurrency safety is in the Outbox/CAS, not a scheduler lock.
4. Test Lua against an owned temporary local Redis when available; never use application Redis/PG credentials. Run shared configuration regressions and host isolated tests.
5. Document enablement after migration, prefix/connection settings, manual repair and eventual-consistency semantics. Do not migrate actual databases, publish packages, upgrade dependencies or commit unrelated work.

## Deployment contract

- Replication is opt-in with THEME_SNAPSHOT_REDIS_ENABLED after the Outbox migration is installed. Default prefix wl:site:config:theme; suffix is the actual site ID, not hard-coded 910024.
- Both hosts must share Redis endpoint/database/prefix. No payload or widget Key is logged, returned by the worker, or duplicated into the Outbox.
- Database/Outbox errors roll back source changes. Post-commit Redis errors leave committed changes and pending retry, never claim rollback. Save success is not a promise of Redis availability/durability.
- --rebuild explicitly re-enqueues latest committed snapshots, including already-synchronized rows after cache loss. --site restricts operator repair; no new HTTP surface.
