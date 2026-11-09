# Shared tenant brand domains

`DomainConfigStore` is the common Platform / Agent implementation for access-domain
validation, primary-domain selection, revision checking and atomic snapshot publication.
It uses the existing `control_plane.wl_tenants`, `wl_sites`, `wl_site_domains` and
`wl_site_config_publications`, `wl_site_config_snapshots` and `wl_site_config_domains` tables. The old global `wl_config_snapshots` is not written. The existing primary-domain migration and unique indexes
must already be deployed. No new table or scheduled task is introduced.

Hosts authorize identity before invoking the store. Agent passes the authenticated
white-label tenant, enables `enabledSitesOnly`, and never accepts a tenant/publisher ID
from the browser. Selected sites are rechecked for tenant ownership and enabled status
inside the write transaction. This phase selects the first eligible site by ascending site ID; saves must match that site.

`save()` requires an audit callback that runs in the same transaction. Platform logs
the real administrator in `admin_operation_log`; Agent logs the white-label account in
`control_plane.wl_operation_log`. Snapshot `published_by` preserves the actor namespace
(`platform-admin:<id>` / `white-label-admin:<id>`), avoiding numeric-ID collisions.

The existing publisher lock order, schema-v4 checksum checks, old-snapshot immutability,
and global domain uniqueness apply. Requests include the edited site’s `expected_config_version`.
Primary-only changes do not republish access-domain lists. No URL/DNS fetches are made.

Verification: `php tests/tenant_brand.php`, host `TenantBrandDomainTest` /
`WhiteLabelBrandTest`, and Platform's isolated `scripts/test-tenant-brand-postgres.sh`.
