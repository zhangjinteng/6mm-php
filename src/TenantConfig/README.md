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

## Access snapshot v4/v5 compatibility

`ConfigSnapshotWriter::latest()` reads the newest record for the requested site before checking its format and raw payload digest. It accepts v4/v5 only and rejects unknown storage, malformed references, a schema/reference mismatch, or a bad newest record; it never falls back to an older v4 record.

The schema column is determined by the retained email reference: missing/empty storage means v4, `rows` means v5. It is not a per-save counter and is not inserted into the single-site payload as a `schemaVersion` field. Site publication and mail configuration versions remain independent.

When an existing site uses rows, non-email domain/module/availability saves preserve its complete published `emailTemplateRef`. `PublishedEmailReference` checks the site's immutable manifest and all corresponding body records against their raw-byte digests and identities before appending the new site snapshot. Frozen legacy source content is not consulted, and mail configuration, body/version records and email audits are not rewritten. Missing/extra/mismatched records fail within the caller's existing transaction.

The compatibility reader does not itself initialize email sources or migrate legacy sites. Email writes use the shared PHP controlled publisher below, never direct editor CRUD. Deploying the reader alone does not switch a site to rows.

Regression: `php tests/site_snapshot_formats.php` (isolated SQLite; no real publication or email delivery).

## Controlled email-brand publisher (Platform + White Label)

`SiteEmailPublisher` implements the documented rows publication contract in PHP, without invoking Go. Both hosts accept only brand name, Logo and default language plus the expected **mail** version; operator and tenant scope come from their authenticated server context. The Platform routes reuse brand read/edit permissions; White Label routes reuse its brand page permission and restrict ownership to enabled sites in the authenticated tenant.

- `detail()` returns the current brand, mail version and independent site publication watermark. It never initializes on read. Existing legacy versions are not mistaken for first version zero.
- `publish()` manages the same-connection transaction, obtains publication locks, checks the latest target snapshot/source/domain-index agreement, validates the expected mail version, language and exact HTTPS image-host allowlist, and prepares a trusted `EmailPublication` for `ConfigSnapshotWriter::append()`.
- One transaction updates/inserts the brand source, initializes per-language source items only when missing, appends an immutable manifest and complete immutable item-version set, records the mail audit and host operation audit, and publishes the schema-5 site snapshot. No full body aggregate is stored in the brand row or manifest. Failed validation/audit/insert rolls the transaction back.
- Existing rows body payloads are copied as exact raw bytes with their existing hashes. Existing source subject/text/html strings are verified and left untouched. Legacy migration retains these strings and leaves the old table/version bytes frozen; only empty/missing legacy collections copy the PHP public presets (four purposes × en/zh-CN). There is no other-site inheritance and no template/policy/SMTP mutation API.
- Omitted/null Logo preserves; an empty string clears. The HTTP adapters recover this distinction from the raw JSON because Laravel converts empty strings to null. Omitted/empty name and language retain the current values. Same-version concurrent saves produce one winner and one conflict, with no overwrite retry.
- New white-label base publication prepares email version 1 directly; account edit calls `initializeIfMissing()` on its selected owned site and never resets an existing config. Missing domains or corrupt existing state produce an error, not an unpublished/default fallback.
- Canonical JSON uses encoding/json-style lowercase HTML escapes, and hashes cover exact emitted bytes. A brand-only change does not alter auth policy switches/version or issued codes. Static image URLs inside existing templates are not rewritten when the brand Logo changes.

Environment prerequisites, in **both hosts**, before enabling writes:

```dotenv
SITE_EMAIL_CONSUMERS_V5_READY=true
SITE_EMAIL_RESOURCE_HOSTS=approved-image-host.example
```

The readiness flag defaults to false. Apply the separately managed migration 9 (plus existing single-site/FK prerequisites) and upgrade all actual consumers before enabling it. Resource hosts must match User `emailAuth.resourceHosts`; no wildcard/implicit host is accepted. Empty Logo/default initialization works without an image host. This feature does not execute migrations, grant User access, restart consumers or send email.

To roll out the neutral white-card default for new sites, record the maximum existing
`wl_sites.site_id` before opening new sites with the release, then set
`SITE_EMAIL_WHITE_PRESET_AFTER_SITE_ID` to that value in **both hosts**. Keep the same
cutover on later deployments. Only a site above that boundary with no existing
snapshot or template source uses the new layout; existing and unpublished pre-cutover
sites are not migrated. Unset or invalid values keep the previous presets. Subjects,
plain text and site/Logo variables are unchanged.

Tests: `php tests/site_email_publication.php`; `bash scripts/test-site-email-postgres.sh` creates an isolated local PostgreSQL cluster and checks concurrent saves, real FKs and rollback. It never targets application databases.

## Generic Redis snapshots (Agent + Platform)

The existing physical table control_plane.wl_site_theme_redis_outbox is retained and generalized. There is **no additional synchronization table**. Existing provider/class/command names remain compatible; implementations support both resource types:

| resource_type | resource_id | locale | tenant_id / site_id | JSON source |
| --- | --- | --- | --- | --- |
| theme | site_id | empty string | owning tenant and site | exact row in wl_site_config_themes |
| agreement_template | wl_agreements.id | actual template locale, e.g. en | both NULL | published platform template only |

Subjects stay inside themes as the exact agreement variable names in `config.legal.entity`: `WHITE_LABEL_NAME` is **wl_tenants.tenant_name**; `LEGAL_ENTITY_NAME`, `REGISTRATION_NO`, `REGISTERED_ADDRESS`, `EFFECTIVE_DATE`, and `SUPPORT_EMAIL` come from the site's non-merchant partner_account_profiles fields legal_entity_name, registration_number, registered_address, effective_date, and support_email respectively. Root name remains the site's display name, and config.supportEmail is retained. Merchant profiles and profiles of other sites are never used. Missing values are null, never replaced by a site name or example. The former camelCase entity keys are no longer emitted.

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
AGREEMENT_TEMPLATE_REDIS_KEY_PREFIX=wl:config
~~~

config/theme_snapshot_redis.php in each host directly reuses existing SHARED_REDIS_* then REDIS_* settings for URL, scheme, host, username, password, port, DB, connect timeout and read timeout. No theme-specific connection/credential variables are needed or read; old THEME_SNAPSHOT_REDIS_URL / _SCHEME / _HOST / _PORT / _USERNAME / _PASSWORD / _DB / _CONNECT_TIMEOUT / _READ_TIMEOUT overrides are ignored. Only the enabled switch and business Key prefixes remain theme/template-specific. URL components take precedence over corresponding individual connection fields. A dedicated, prefix-free connection is still used: app-specific prefixes do not alter these keys. TLS and ACLs are supported by the existing settings. Feature defaults to disabled. Disabling stops the binding/command/schedule, not removal of existing keys. Changing existing SHARED_REDIS_* / REDIS_* values also affects their other consumers; ensure both hosts resolve to the same Redis instance and DB.

Changing prefix or destination requires a rebuild of **each type**; previous acknowledgments refer to the old destination.

### JSON and Redis contracts

- Theme key: wl:site:config:theme:910024; existing Hash fields remain site_id, config_version, schema_version, payload, payload_sha256, previous_config_version.
- English template keys use the configured prefix plus the public agreement name: TPL-LEGAL-001 / terms → wl:config:agreement; TPL-LEGAL-002 / privacy → wl:config:privacy; TPL-LEGAL-003 / risk → wl:config:risk; TPL-LEGAL-004 / cookies → wl:config:cookies; TPL-LEGAL-005 / aml → wl:config:aml. Database IDs and `:en` are not appended. Additional locales retain isolation with a suffix, e.g. wl:config:agreement:zh-CN. Hash fields remain template_id, locale, config_version, schema_version, payload, payload_sha256, previous_config_version. Payload contains templateId (numeric row ID as a string), code (e.g. TPL-LEGAL-001), agreementType (terms/privacy/risk/cookies/aml), locale, name, enabled, publishedVersion (business revision as a string), and ordered sections (key/title/body_html).
- When upgrading from ID/locale keys, republish each template or run the template rebuild command below to populate the named keys, even if the previous Outbox version was already acknowledged. Existing old keys are not deleted automatically.
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
- Subject saves create new themes with the six uppercase agreement variables in legal.entity. Immutable theme history is not rewritten. Rebuilding an old theme republishes that old JSON; save the site's current configuration to generate a new theme with the current entity format, even if the source values are unchanged.

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

Connection reuse regression (synthetic environment only, no database or Redis IO): run `THEME_REDIS_TEST_HOST=/absolute/path/to/agent_admin php tests/theme_redis_config.php`, then repeat with the local platform_admin path. It checks both configuration files, shared/default fallback, ACL credentials, timeouts, URL precedence, prefix isolation, and ignores obsolete theme-specific connection settings.
