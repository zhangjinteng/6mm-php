# Scoped liquidation warning rules

Both Agent and Platform use `LiquidationWarningRulesService` against the existing `source_config` table with `config_key=liquidation_warning_rules`.

| Scope | home_site_id | agent_id |
| --- | --- | --- |
| Platform defaults | 0 | 0 |
| White-label site / direct users | positive site ID | 0 |
| Main merchant | positive site ID | positive main merchant ID |

Reads inherit whole records: exact merchant → same-site white label → platform (0,0). A white label starts at (site,0). Only a missing row falls back; explicit notification `false` is an override, not absence. The existing system_config/risk_control_config compatibility and hardcoded safe defaults are retained only if no source_config record is found. No history or existing configuration rows are rewritten. Negative IDs and (0,positive agent) requests are rejected.

Writes update only the exact server-authorized scope. Thresholds are UI percentages, persisted/published as fractional ratios; extra keys already present in that exact config JSON are preserved. Existing synchronous RPC acknowledgment and DB transaction behavior remains unchanged. This is not a distributed transaction: a successful RPC followed by DB commit failure still requires reconciliation. No automatic whole-transaction retry is added.

## Agent identity and permissions

- `UserAccessScopeResolver::current()` derives identity from the API guard on each request. White-label enabled sites are ordered by site_id ascending. The warning editor uses the first site only, not the login account ID or a client-selected tenant/site.
- `warningScope()` narrows only the warning list to that same first site and its direct users (`agent_id=0 OR NULL`). Other user/contract pages retain their existing scopes. Merchant lists retain the main merchant and its child-account user range.
- `warningRuleScope()` returns (first site,0) for white labels, or the main merchant's (home_site_id,id) for merchants. No enabled white-label site returns null: the list is empty and rules read/save is denied; null must never become a global write. If the first site is disabled, a later request selects the next currently enabled owned site.
- White-label reads require `liqWarning:lists`. Owners/shared super roles have full page editing rights; delegated roles additionally require the active, non-deleted `liqWarning:edit` permission from their own available role. The POST whitelist and controller use the same `WhiteLabelAccess::canEditWarningRules` policy. Existing merchant role-1 / liqWarning:edit behavior is retained.
- Save bodies cannot select agent_id, home_site_id, site_id or tenant_id. Other trading mutation endpoints remain blocked for white labels. No role/menu database rows are created or granted by this source change; delegate edit permissions must exist/be assigned through the normal role management workflow.
- The existing frontend already obeys server `can_edit`; no UI permission bypass is introduced.

## Platform and deployment

Platform's existing `LiquidationWarningConfig` adapter reads/writes only (0,0), with its existing authorization. Both hosts share the new inheritance code. No migration, new table, package publication or vendor change is included.

Before a future rollout, confirm the running TradeKernel service supports home_site_id/agent_id scoped risk configuration and the same inheritance (merchant → white-label site → platform). SDK support alone does not prove server deployment; old servers may ignore these fields. Tests use source-autoload overrides, SQLite and fake RPCs only.

Tests: shared `php tests/liquidation_warnings.php`; Agent LiqWarningControllerTest, WhiteLabelContractManagementTest and UserAccessScopeResolverTest; Platform LiquidationWarningConfigTest and RiskNotificationConfigGatewayTest. The broader Agent role/login/user/funding tests verify that first-site narrowing does not alter other pages.
