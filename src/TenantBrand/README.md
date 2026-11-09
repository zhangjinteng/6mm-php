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

## Site theme and identity

Deploy the host migration `2026_11_10_000010_add_brand_assets_to_wl_site_domains.php`
before saving brand assets. It adds three nullable columns: `template_id`
(`template-1` / `template-2`), `logo_url`, `favicon_url`. Null template values keep
legacy sites unchanged. Each site shares one identity across its primary and backup
domains; domain replacement/addition copies the site's existing identity. Domain-only
clients never clear identity fields they omit.

Detail includes these three fields and `brand_revision`; a client changing any of
them must send `expected_brand_revision`. The existing domain revision and published
config version remain required. A stale brand update or audit failure rolls back
domain and identity changes together. Branding-only saves do not change access-policy
snapshots or entitlements.

`DomainConfigStore::upload()` authorizes the same site's tenant scope then delegates
to the shared `MediaRegistry`. Hosts use `ImageFile::rules()` for JPG/PNG/WebP/SVG/ICO
and 5120 KiB, matching the registry's byte-level size/dimension and safety checks. Uploading is separate from saving: media usage
becomes active only when the brand is saved. Platform and Agent share the same media
usage keys so changing/removing either image releases the previous reference. No
credentials or image bytes are stored in the domain table. The two preview SVGs are
static UI assets. Uploaded SVGs must also pass the shared static-SVG security policy.
