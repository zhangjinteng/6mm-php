# Site configuration publication

Both hosts use `ThemeSnapshotWriter::sync(tenant, site, operator, actor, reason, publicationVersion?)` to append `control_plane.wl_site_config_themes`.

- The table must be installed using the agreed DDL before deploying this code. Missing source/theme tables fail closed; there is no runtime schema creation or fallback that silently skips writing.
- The caller must authorize its actor and tenant/site and run the writer on the same connection and transaction as the source mutation. A null site selects the first site belonging to the trusted tenant; an explicit site must belong to it.
- The writer serializes on the tenant row, reads the actual source tables, validates the existing snapshot checksum/site, and appends a full schema-version-1 JSON document only if it differs. Missing records are initialized, including on account creation/edit. It never stores account credentials or customer-service keys.
- ConfigSnapshotWriter delegates after access/auth publication. New theme rows reuse that publication version if it is newer than the previous theme row. Theme-only saves allocate from the existing `control_plane.wl_snapshot_version_seq`; those sequence values need not appear in `wl_site_config_publications`. Version gaps are expected. The theme version is not required to equal the latest access snapshot version.
- Brand, feature, SEO, channel and compliance saves use the same writer. Channel settings remain tenant-scoped and synchronize the first site (current single-site policy). No tenant-wide fan-out or new customer-service provisioning behavior is added.
- Missing optional settings are null/empty, never example data. Missing module rows mean disabled. Templates allow null, template-1 and template-2. `seconds` maps to prediction; feature flags are selected only from feature-classified rows.
- Legal documents include all five existing agreement types. `usePlatformTemplate` marks platform mode; `source.url` contains only a valid published custom URL (including a retained custom URL when platform mode is selected). Pure inline content without a published URL has null. Draft URLs and template bodies are not exposed.
- Google verification tokens are included only when bound to the current primary domain. SEO translations do not imply enabled site locales. Customer service remains on its existing APIs.
- Tenant availability is mapped to active/inactive. Domain bindings use hostnames (domain), not synthesized origins or binding IDs.

Tests: `php tests/site_themes.php`, shared tenant configuration tests, and the hosts' isolated tenant/account configuration tests. Never use real application database credentials for tests.
