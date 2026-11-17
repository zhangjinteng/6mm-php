# Historical liquidation ownership

The active Platform and Agent liquidation routes use the validated CK owner
policy shared with trade fills (`TradeFillOwnerScope`/`TradeFillOwnerDirectory`).

- Total admin: merchant = positive site + positive agent; white label = positive
  site + zero agent; platform = zero site + zero agent. All is their union.
- White-label login: first enabled owned site ordered by site ID + zero agent.
- Merchant login: main merchant's site + main merchant ID. Child logins inherit
  the corresponding principal; request IDs cannot widen scope.
- Unknown/no enabled owner denies access rather than falling back to platform.

Platform keeps its existing liquidated-position list/status filter. Agent keeps
its existing actual-fill grouping and fee/ledger reconciliation. This change
does not unify those two aggregation populations or recalculate historical data.

`LiquidationTradeListQueryService::searchForOwner` applies the same CK event owner
before counting, grouping and pagination, while preserving the LIQUIDATION-only
and existing user-type constraints. Active Agent routes do not use the older
merchant-array/sentinel adapter. It remains available for legacy callers.

Historical identity hydration is page-only: moved, deleted or missing users do
not remove already authorized CK rows, and current user ownership never replaces
the historical owner. Total-admin source names are enriched from those CK IDs.

Test with `php tests/liquidation_owners.php` plus each host's liquidation-owner
and existing fee-reconciliation tests. Verification must use isolated databases,
HTTP fakes, or read-only live queries; no schema/data migration is needed.
