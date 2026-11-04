# Frontend feature-entry configuration

`FeatureConfigStore` supports only `trade_ranking` in `control_plane.wl_site_modules` (`module_type=feature`). Missing records default to enabled and reads never create records. Business modules, site entitlement versions, publication snapshots and historical data are not modified.

Platform uses the default policy (first site). Agent passes `enabledSitesOnly=true`, `allowSiteSelection=true` and a tenant ID derived from its authenticated white-label account. Never accept that tenant ID from an Agent request. The shared service checks tenant/site ownership and status again within the transaction.

Save receives one strict boolean and the exact `expected_updated_at` (null for missing rows). It rejects classification collisions and stale versions, increments timestamps monotonically, skips unchanged writes, and invokes the mandatory host audit callback inside the transaction. Audit failure rolls back configuration. PostgreSQL uses the existing publisher's lock order and bounded transaction timeouts.

The host retains route/menu permissions and its own actor/audit namespace. Do not cast a white-label account ID into the Platform administrator namespace. This extraction does not publish packages, run migrations or upgrade host dependencies.
