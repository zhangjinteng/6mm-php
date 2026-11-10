# Generic configuration Redis synchronization implementation plan

**Goal:** Reuse wl_site_theme_redis_outbox for site themes and published platform agreement templates, with no additional database table. Add config.legal.entity.whiteLabelName from the owning tenant, never the site name.

**Architecture:** The existing table becomes a coalesced latest-snapshot Outbox keyed by resource_type/resource_id/locale. Store exact payload/schema/checksum/version and scope in the same source transaction. After the outer commit, deliver via the shared version-safe Redis publisher; retries use the captured payload, not current draft/source content. Subject information remains in themes, not a separate resource type. Platform is the only template editor; Agent shares the replication service and worker without new editing permissions.

**Tech Stack:** PHP/Illuminate 8, PostgreSQL in-place ALTER migration, SQLite unit fixtures, owned temporary PostgreSQL/Redis sockets for integration tests.

## Steps

1. Generalize schema creation and add an idempotent, transactional PostgreSQL upgrade of the existing table: backfill old theme rows, remove site-only PK/FK, install composite PK, preserve rows, reset acknowledgments for repair. No staging/replacement table, and no destructive down migration.
2. Generalize shared Outbox/publisher, adding platform-template projection and typed repair/filtering. Keep theme API and Redis keys backward compatible. Add configurable template prefix.
3. Hook template publish and unchanged republish in TenantAgreementService, before audit/commit; draft save must never enqueue or expose draft text. Use shared library interfaces for both hosts.
4. Add whiteLabelName to entity, retaining name as legal company name and root name as site display name. Add scope and profile isolation tests.
5. Verify source+Outbox rollback, after-commit delivery, retries from captured JSON, template/locale/site key isolation, same-version conflicts, int8 version safety, data-preserving legacy migration, missing-source migration rollback, and both host integrations.
6. Update shared README with full table and payload contract, commands, migration/rollout order, and no live deployment. Preserve unrelated dirty work; do not publish, run live migrations, upgrade dependencies or commit.

## Contract decisions

- Types: theme (site ID, locale empty, tenant/site populated) and agreement_template (wl_agreements.id, actual locale, tenant/site NULL).
- Templates contain only current published sections and metadata, with variable placeholders retained; no drafts, tenant profile values or admin credentials.
- Templates get a separate configuration delivery version; business publishedVersion remains inside JSON. Identical republish keeps delivery version and repairs cache.
- Outbox stores one latest snapshot per object; it is not a template history archive. Theme history stays in wl_site_config_themes.
- Keep existing themes:sync-redis command; default retry covers both types. Explicit template rebuild uses --type=agreement_template, with optional --template/--locale. Legacy --site and untyped --rebuild remain theme-only.
- PostgreSQL is authoritative; post-commit Redis failure remains pending, never pretends to roll back committed data. Full payload (including theme widget Key) is excluded from logs/CLI output.

## Connection configuration simplification

Per the user's follow-up, both host configuration files read existing SHARED_REDIS_* / REDIS_* directly, including ACL username and timeouts. Remove theme-specific connection overrides; keep the enabled switch and business Key prefixes. Retain the prefix-free transport and existing Outbox delivery behavior. Verify both actual host configuration files with synthetic environment variables (without loading application .env, connecting to Redis, clearing live caches or updating business data). No publication or dependency changes.
