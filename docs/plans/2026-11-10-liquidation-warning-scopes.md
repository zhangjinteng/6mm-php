# Liquidation Warning Scope Inheritance Implementation Plan

> Execute in the current workspace, preserving unrelated changes. No release, dependency upgrade, live migration, or business RPC/DB writes.

**Goal:** Support platform (0,0), merchant (site,main merchant), and white-label (first owned enabled site,0) rule editing with consistent inheritance and tenant isolation.

**Architecture:** Keep one shared LiquidationWarningRulesService for all source_config reads/writes. Resolve the trusted Agent identity through UserAccessScopeResolver; use a warning-specific first-site scope for white-label lists and rules without narrowing other pages. White-label edit authorization uses its real role/menu definitions, not merchant IDs. Existing RPC publication remains scoped and synchronous; no new cross-store atomicity claim.

**Tech Stack:** PHP/Illuminate 8, Vue shared dialog, SQLite isolated HTTP tests and fake trade RPCs.

## Tasks

1. Extend `tests/liquidation_warnings.php` with exact merchant → site white-label → platform → existing legacy fallback, whole-record overrides, explicit false, isolation, and invalid (0,positive) scope cases. Confirm failure before changing `src/LiquidationWarnings/LiquidationWarningRulesService.php`; implement minimal shared inheritance.
2. Extend Agent `UserAccessScope` with warning-specific first-site scope and nullable rule scope for no enabled site; order resolver sites by site_id. Add white-label edit policy in `WhiteLabelAccess`, admitting only the exact POST rules endpoint; preserve list permission, owner/super role behavior, and require liqWarning:edit for delegates.
3. Change `LiqWarningController` to use trusted scopes for reads/lists/saves. No enabled sites means empty list and no writable rules. Reject scope injection. Return existing rule fields/can_edit without frontend API changes. Remove merchant-only save resolution.
4. Update merchant controller tests and white-label contract HTTP fixtures for first-site lists, inheritance, permissions, no-site fail-closed, cross-tenant/disabled/second-site rejection and fake RPC failure rollback. Keep unrelated trading writes blocked.
5. Verify Platform still reads/writes only (0,0) through the same shared service and retains existing permissions. Run isolated Platform risk configuration tests with a source autoload override, never modify vendor or connect to real services.
6. Document inheritance, first-site semantics, delegate permission and server-version requirement. Run PHP lint/diff checks plus shared/Agent/Platform related tests. Do not publish.

## Scope and failure rules

- Valid scopes are exactly (0,0), (positive,0), (positive,positive); reject negative and (0,positive).
- An existing scoped row overrides as a whole, including explicit notification false; absence alone triggers parent fallback.
- White-label first site = lowest site_id among currently owned enabled sites. Lists use only direct users with agent_id=0/NULL from that same site. Other user/contract pages keep their existing multi-site scope.
- No enabled site must never become a platform write. Client-supplied ownership cannot select any target.
- Platform keeps its existing (0,0) adapter. Shared source changes require a future package deployment; this task does not release or edit installed vendor.
- Active trade service must support scoped risk notification RPCs before deployment; old servers may ignore the scope fields.
