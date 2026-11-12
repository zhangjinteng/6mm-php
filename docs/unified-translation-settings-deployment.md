# Unified translation settings deployment

## Scope

- All active settings reads/writes use `cms.translation_settings` via `Cms\TranslationSettingsStore`.
- Platform: canonical `cms.spaces` default template settings. White label: each authorized site's own space settings. Merchant: `merchant:{mainAgentId}`, shared with its subaccounts, never a site/platform fallback.
- This does not grant merchants access to white-label-only CMS or SEO routes.
- Target languages, source language, revision and verification metadata are added to the existing settings table; existing spaces retain a compatibility mirror and foreign key.
- Frontend settings API shapes and permission rules stay unchanged; the old merchant HTTP contract is a facade over the same storage, not another writer.

## Mandatory precautions (destructive migration)

1. Back up `agent_translation_config`, `cms.translation_settings`, `cms.spaces`, migration history, and the old credential encryption key securely. Do not put secrets in logs or Git.
2. Stop all old settings writers on every Agent/Platform instance and drain translation jobs. Schema backfill may replace legacy shared setting IDs with per-space copies; in-flight jobs using old IDs can fail closed and need resubmission. Use a maintenance window, not a mixed-version rolling deployment.
3. Both hosts must have the **same existing `CMS_TRANSLATION_ENCRYPTION_KEY`**. Do not generate/rotate it during this migration: that would invalidate existing CMS keys. Run merchant migration from the Agent deployment with the correct historical Agent `APP_KEY`; Platform's APP_KEY cannot substitute for it.
4. Deploy the updated shared PHP code to both hosts before invoking either new migration. Source-only development does not make installed vendor packages current automatically. Deploy matching host code and shared sources together before resuming traffic.
5. Run Agent migration `2026_11_12_180100_migrate_merchant_translation_settings` first. It upgrades CMS schema/backfills spaces and copies merchant credentials/options, then compares values and decrypts the target key for verification. It locks the old PostgreSQL table to prevent concurrent legacy writes. All rows must match before `DROP TABLE agent_translation_config`; any failure, including dependency/DDL failures, rolls back the transaction. Resolve errors; do not bypass checks with manual DROP.
6. Run Platform migrations afterward. Its `2026_11_12_180000_unify_translation_settings` migration performs an idempotent CMS schema/backfill. The two migration names deliberately differ so shared migration history cannot skip merchant data migration if Platform runs first. Platform intentionally does not decrypt/drop Agent legacy data. Keep both hosts stopped until Agent migration succeeds.
7. Verify platform settings, two independent white-label sites, main merchant and subaccount settings, preview translation, and a CMS job. Confirm only the main merchant row changes on merchant saves. If using multiple databases, migrate each independently with its own historical key.

## Rollback and limitations

- The old table is removed **when you execute the Agent migration**, not by editing these source files. No new table is introduced.
- `down()` deliberately refuses automatic rollback. Restore a consistent backup under stopped traffic, deploy the matching previous code, and reconcile any later saves before reopening. PostgreSQL rollback cannot restore a deliberately committed drop without backup.
- Runtime code has no read fallback or dual write to the old table. Old application binaries cannot run after the drop.
- Existing new merchant keys that disagree with legacy records cause a migration conflict, not a silent overwrite/deletion. Resolve the source of truth explicitly.
- Damaged or unknown legacy ciphertext aborts the entire migration; save/recover valid keys before retrying. New encryption uses CMS's dedicated encryption domain, not Agent APP_KEY.
- Provider verification is outside save locks. Persistence uses a unique config key, revision checks and row locking; failed provider verification or stale forms cannot overwrite saved configuration.
- Verification metadata is migrated, but historical provider error text is discarded to avoid copying secrets.

## Verification performed during source development

Use shared `composer test`, Agent translation/permission tests, Platform symbol-tag HTTP tests, and Platform `TranslationServicePostgresTest` against a disposable **local** `cms_migration_test_*` database only. The latter includes real PostgreSQL constraints, migration/drop rollback, separate site settings and CMS worker regression tests. No production migration is part of this source-only task.
