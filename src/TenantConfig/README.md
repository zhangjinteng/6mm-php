# Site configuration publication

Both hosts use `ThemeSnapshotWriter::sync(tenant, site, operator, actor, reason, publicationVersion?)` to append `control_plane.wl_site_config_themes`.

- The table must be installed using the agreed DDL before deploying this code. Missing source/theme tables fail closed; there is no runtime schema creation or fallback that silently skips writing.
- The caller must authorize its actor and tenant/site and run the writer on the same connection and transaction as the source mutation. A null site selects the first site belonging to the trusted tenant; an explicit site must belong to it.
- The writer serializes on the tenant row, reads the actual source tables, validates the existing snapshot checksum/site, and appends a full schema-version-1 JSON document only if it differs. Missing records are initialized, including on account creation/edit. Account credentials are never stored.
- Per the explicit frontend integration contract, config.channels.support contains provider "6mm" and publicWidgetId equal to the saved plaintext appKey ONLY when the site's business-classified customer_service flag is enabled and the tenant has a bound 6MM provider with a valid appKey. Otherwise both fields are null, including when the switch is closed. Legacy ciphertext is never projected or decrypted here. Theme snapshots and their historical versions must be treated as containing the widget key; admin toggle DTOs and audit logs remain redacted.
- ConfigSnapshotWriter delegates after access/auth publication. New theme rows reuse that publication version if it is newer than the previous theme row. Theme-only saves allocate from the existing `control_plane.wl_snapshot_version_seq`; those sequence values need not appear in `wl_site_config_publications`. Version gaps are expected. The theme version is not required to equal the latest access snapshot version.
- Brand, feature, SEO, channel and compliance saves use the same writer. Channel settings remain tenant-scoped and synchronize the first site (current single-site policy). No tenant-wide fan-out or new customer-service provisioning behavior is added.
- Missing optional settings are null/empty, never example data. Missing module rows mean disabled. Templates allow null, template-1 and template-2. `seconds` maps to prediction; feature flags are selected only from feature-classified rows.
- Legal documents include all five existing agreement types. `usePlatformTemplate` marks platform mode; `source.url` contains only a valid published custom URL (including a retained custom URL when platform mode is selected). Pure inline content without a published URL has null. Draft URLs and template bodies are not exposed.
- Google verification tokens are included only when bound to the current primary domain. SEO translations do not imply enabled site locales. Customer service remains on its existing APIs; manual binding saves also synchronize the theme inside their local transaction, while lifecycle toggles synchronize at local publication.
- Tenant availability is mapped to active/inactive. Domain bindings use hostnames (domain), not synthesized origins or binding IDs.

Tests: `php tests/site_themes.php`, shared tenant configuration tests, and the hosts' isolated tenant/account configuration tests. Never use real application database credentials for tests.

## Generic Redis snapshots (Agent + Platform)

The existing physical table control_plane.wl_site_theme_redis_outbox is retained and generalized. There is **no additional synchronization table**. Existing provider/class/command names remain compatible; implementations support both resource types:

| resource_type | resource_id | locale | tenant_id / site_id | JSON source |
| --- | --- | --- | --- | --- |
| theme | site_id | empty string | owning tenant and site | exact row in wl_site_config_themes |
| agreement_template | wl_agreements.id | actual template locale, e.g. en | both NULL | published platform template only |

Subjects stay inside themes: config.legal.entity.whiteLabelName is **wl_tenants.tenant_name**, entity.name is the company's legal name, and root name is the site's display name. Registration/address/effectiveDate stay in entity; agreement contact email remains config.supportEmail. Merchant profiles and profiles of other sites are never used. A missing white-label name is null, never replaced by a site name or example.

### Physical schema and migration

The same table now has:

| Column | Type | Meaning |
| --- | --- | --- |
| resource_type | varchar(32), NOT NULL | theme / agreement_template |
| resource_id | bigint, NOT NULL | site ID or platform template row ID |
| locale | varchar(32), NOT NULL, default '' | empty for themes, actual locale for templates |
| tenant_id | bigint, NULL | required for themes; NULL for public platform templates |
| site_id | bigint, NULL | equals resource_id for themes; NULL for templates |
| config_version | bigint, NOT NULL | version of the captured Redis projection |
| schema_version | bigint, NOT NULL | payload schema version, currently 1 |
| payload | text, NOT NULL | exact committed JSON, max 1 MiB |
| payload_sha256 | varchar(64), NOT NULL | SHA256 of exact JSON bytes |
| previous_config_version | bigint, NULL | previous projection version, not a historical body archive |
| synced_version | bigint, NULL | Redis acknowledgment; NULL means pending |
| attempts | integer, default 0 | current target failure count |
| next_attempt_at | timestamptz, NOT NULL | earliest retry time |
| last_error_code | varchar(64), NULL | sanitized code only |
| synced_at | timestamptz, NULL | acknowledgment time |
| updated_at | timestamptz, NOT NULL | most recent enqueue time |

Primary key: **(resource_type, resource_id, locale)**. The old site-only primary key and theme-specific foreign key are removed. PostgreSQL checks enforce resource scope, versions and payload bounds; a partial index covers pending retries. This table retains one latest projection per resource, not full template history. Theme history remains in wl_site_config_themes.

Deployment order (source only; no package release or business-data migration executed):

1. Pause/disable old sync workers and configuration writers during the incompatible table-contract upgrade; mixed old/new writers are not supported.
2. Deploy the shared library and both host adapters together, leaving Redis replication disabled until the schema is ready.
3. Apply 2026_11_10_120000_create_wl_site_theme_redis_outbox.php for a fresh installation and/or 2026_11_10_130000_generalize_wl_site_theme_redis_outbox.php for an existing installation. Shared schema code detects either state. PostgreSQL upgrade uses a transactional **ALTER on the same table**, validates and copies each original theme's payload/owner from the matching theme version, preserves records, and resets acknowledgments for repair. Missing/corrupt source snapshots abort and roll back the entire upgrade. Both hosts' migrations are idempotent. No temporary/replacement table is created.
4. The upgrade cannot be automatically downgraded without losing template data; its down() deliberately refuses destructive rollback. Take a verified backup before deployment. SQLite isolated tests create the current schema directly; legacy in-place upgrade is supported only for production PostgreSQL.
5. Configure both hosts with the same Redis destination/prefixes, rebuild Laravel's config cache using the normal deployment procedure, then enable:

~~~dotenv
THEME_SNAPSHOT_REDIS_ENABLED=true
THEME_SNAPSHOT_REDIS_KEY_PREFIX=wl:site:config:theme
AGREEMENT_TEMPLATE_REDIS_KEY_PREFIX=wl:config:agreement-template
~~~

config/theme_snapshot_redis.php in each host also supports THEME_SNAPSHOT_REDIS_URL / _SCHEME / _HOST / _PORT / _USERNAME / _PASSWORD / _DB / _CONNECT_TIMEOUT / _READ_TIMEOUT. Endpoint values fall back to SHARED_REDIS_* then REDIS_* (see config for defaults). A dedicated, prefix-free connection is used: app-specific prefixes do not alter these keys. TLS and ACLs are supported by the connection configuration. Feature defaults to disabled. Disabling stops the binding/command/schedule, not removal of existing keys.

Changing prefix or destination requires a rebuild of **each type**; previous acknowledgments refer to the old destination.

### JSON and Redis contracts

- Theme key: wl:site:config:theme:910024; existing Hash fields remain site_id, config_version, schema_version, payload, payload_sha256, previous_config_version.
- Template key: wl:config:agreement-template:123:en; Hash fields are template_id, locale, config_version, schema_version, payload, payload_sha256, previous_config_version. Payload contains templateId (numeric row ID as a string), code (e.g. TPL-LEGAL-001), agreementType (terms/privacy/risk/cookies/aml), locale, name, enabled, publishedVersion (business revision as a string), and ordered sections (key/title/body_html).
- Delivery config_version differs from business publishedVersion. Projection changes allocate a version from the existing wl_snapshot_version_seq; no-op republish retains the version. Lua compares full decimal strings, not floating-point numbers. Version gaps are expected.
- Templates retain placeholders such as {{WHITE_LABEL_NAME}}. They contain **no substituted tenant data, no draft_sections, and no administrator credentials**. Consumers combine the published template with the authorized site's subject data and HTML-escape subject values before substitution into HTML.
- A null previous version is the empty string in Redis. Hashes have no TTL, but eviction/flush is possible. SHA256 covers exact JSON bytes.
- The Outbox now contains full theme payloads, including the deliberately public customer widget appKey. Protect its read access/backups like theme history and Redis. Never log full snapshots, widget Keys or raw driver exceptions.

### Publication, transactions and failure semantics

- Subject save uses ComplianceConfigStore → ThemeSnapshotWriter. Same transaction writes source profile, theme history and theme Outbox payload. No separate subject resource/cache is introduced.
- Template **save draft does not enqueue**. Only authorized Platform publish/unchanged republish calls shared AgreementTemplatePublication inside its transaction, before audit/commit. Both hosts share replication and worker; Agent gains no template-editing endpoints or permissions.
- Normalization/purification and permission checks remain in the editor. AgreementTemplateSnapshot copies only published metadata/sections, rejects site-level and unpublished rows, and retains variables.
- Source mutation, captured payload and Outbox target commit together. Schema/validation/Outbox failure rolls them all back; Redis IO only happens after the outermost commit. Audit failure after enqueue also discards the callback.
- After commit, delivery is best effort. Redis failure does not roll back committed PostgreSQL or falsely fail the save. Pending rows survive process exit before callback. Scheduled retry runs every minute with 5–300 second backoff.
- Worker retries **the captured Outbox JSON**, never re-reads mutable draft/current source fields. Lua atomically checks/replaces one Hash, rejects equal-version conflicts and prevents old workers overwriting newer projections. Acknowledgments match type/ID/locale/version/checksum, so older workers cannot acknowledge newer targets.
- Unchanged save/republish re-enqueues the same snapshot to repair cache loss. Existing frontend responses are not changed to promise Redis availability; synchronization status remains in Outbox metadata.
- Subject saves create new themes with whiteLabelName. Immutable theme history is not rewritten. Rebuilding an old theme republishes that old JSON; save the site's current configuration to generate a new theme with the field.

### Commands (from either enabled host's PHP directory)

~~~bash
# Retry both types (also the scheduled command)
php artisan themes:sync-redis --limit=100
# Theme-only delivery / repair, unchanged legacy options
php artisan themes:sync-redis --site=910024
php artisan themes:sync-redis --site=910024 --rebuild
# Template-only delivery / repair
php artisan themes:sync-redis --type=agreement_template
php artisan themes:sync-redis --type=agreement_template --rebuild --limit=100 --after=0
php artisan themes:sync-redis --template=123 --locale=en --rebuild
~~~

Normal runs select due pending rows only. Untyped --rebuild defaults to themes; use --type=agreement_template to initialize existing published templates after rollout. Rebuild skips unpublished/site-level templates, and never reads drafts. Rebuild targets one type at a time; repeat with the printed --after cursor until zero objects are selected. --site cannot combine with template/locale filters. No new public HTTP repair endpoint is added.

Rebuild repairs missing keys, not same-version conflicts or higher Redis versions; investigate before deleting the exact affected key. Reconciliation after Redis flush remains an operator rebuild/no-op-save operation, not an automatic scan of acknowledged resources.

Tests: composer test covers shared regressions plus SQLite/fake-Redis checks. theme_redis_lua.php and theme_redis_laravel.php require an owned temporary Redis socket; the latter targets either host without booting a real Kernel/.env. config_redis_pg.php requires an owned temporary PostgreSQL socket and verifies a data-preserving, rollback-safe legacy ALTER with unchanged table names.
