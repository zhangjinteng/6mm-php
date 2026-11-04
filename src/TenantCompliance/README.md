# ComplianceConfigStore

Shared persistence/validation for existing Platform per-site compliance and Agent white-label settings. No new schema or runtime config snapshot is introduced.

```php
$store = new ComplianceConfigStore($connection, enabledSitesOnly: true, schema: 'control_plane');
$detail = $store->detail(['tenant_id' => $authenticatedTenantId, 'site_id' => $siteId]);
$result = $store->save($input, $whiteLabelAccountId, 'tenant', $transactionalAudit);
```

The host must authenticate, enforce page/action permissions and derive tenant ID from its trusted identity. Agent never accepts a tenant/operator/actor selector in the request. `enabledSitesOnly` requires both site and tenant enabled; `sites()` and `detail()` require the supplied tenant. Platform uses its existing admin permissions and can manage inactive sites.

## Data and invariants

- `control_plane.partner_account_profiles`: five compliance fields on the site's `home_site_id`, **`agent_id IS NULL`** profile. Preserve merchant profiles and all non-compliance fields. Do not change this to `agent_id=0`.
- `control_plane.wl_agreements`: five `locale=en` site references/URLs. Platform templates are read, never modified/published. Published metadata only; no draft/body returned.
- Existing custom content is retained, not created/edited by this entry. HTTPS links reject credentials/control/HTML characters and are never fetched.
- Hash revision and tenant/site/profile/agreement locks protect edits and first inserts. PG lock/statement timeouts are 5s/15s. Identical saves do not write or audit.
- Audit callback is mandatory, runs inside the same transaction and receives scope, actor type, profile hash and agreement mode/enable/URL hashes. Failure rolls back all writes. Host writes `admin_operation_log` or `control_plane.wl_operation_log` on the same connection.
- Agreement actor namespaces are `platform` / `tenant`, matching existing database constraints. Profile `owner_admin_id / created_by / updated_by` belong to Platform admins: tenant updates preserve existing owner/creator and clear updater; new tenant profiles leave all three null. Actual tenant operator is recorded in the tenant audit log, not put into Platform admin foreign keys.

Tests: `php tests/tenant_compliance.php`, host HTTP tests and Platform's `scripts/test-tenant-compliance-postgres.sh` (isolated temporary PostgreSQL, real JSONB/date/actor constraints/lock timeout).

Source only: no release, migration, business data writes or dependency upgrade is part of this change. The existing `wl_agreements` schema and initialized five platform templates must exist before enabling the host.
