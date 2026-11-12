# Unified Translation Settings Implementation Plan

**Goal:** Store all translation settings in `cms.translation_settings`, preserving platform-wide, merchant-main-account and white-label-site scopes.

**Architecture:** Shared settings storage owns credentials, locales, revision and runtime options. Host controllers continue to authorize scopes; CMS spaces retain locale/link mirrors for content jobs. A fail-closed Agent migration re-encrypts legacy merchant keys, verifies every row, then drops the old table in the same transaction. No live migration or release is performed during source implementation.

**Tech Stack:** PHP, Laravel 8, PostgreSQL; isolated SQLite/HTTP-fake regression tests.

## Decisions and risks

- Preserve existing `content-space-{id}` keys; merchants use `merchant:{mainAgentId}`. Never infer ownership from operator audit fields.
- Do not invent merchant CMS spaces or grant merchants new CMS/SEO access.
- Use the CMS dedicated encryption key on both hosts; legacy Agent APP_KEY is used only for migration. A broken key aborts the transaction.
- Add source/target locales, revision and legacy verification metadata to the existing settings table. New writes use this table only.
- Keep Google verification outside write locks and recheck revision under the database lock.
- Explicit user clarification authorizes dropping `agent_translation_config` after complete verification. This is not reversible automatically: back up before deployment, stop old writers, run Agent migration before switching traffic. Platform cannot migrate an Agent APP_KEY credential using its own APP_KEY.

## Tasks

1. Add shared schema upgrade/backfill and unified settings store; test migration, re-encryption, idempotence, collision and rollback.
2. Adapt CMS settings and runtime, merchant settings, and compatibility endpoints to shared storage. Keep authorized identities and external API contracts.
3. Add host migrations; Agent migrates/verifies/drops legacy data atomically, Platform upgrades existing CMS configuration only.
4. Update isolated host fixtures and regression tests for merchant/subaccount isolation, site isolation, stale forms and provider/database failures.
5. Run shared tests, related Agent/Platform tests and PHP syntax checks; document deployment order and explicit data-loss/rollback risks. Do not release or run live migrations.
