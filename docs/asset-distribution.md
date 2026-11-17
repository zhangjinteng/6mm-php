# Current account asset distribution

`SixMm\Shared\AssetDistribution\AssetDistributionQueryService` extracts the Platform asset/account distribution query. It is distinct from the period/bucket-based user asset distribution report.

Both hosts read the same current UTC day's `admin_source_asset_daily_statistics` published snapshots through `AssetReportQueryService::currentBalances()`. No live wallet reaggregation, schema creation, snapshot publication or initialization occurs on reads. Funding and contract balances are loaded before filtering/paging. Allocation is each account's valuation / the same source's combined two-account valuation, using BC decimal strings at scale 16. Account filters/page size do not change the denominator. NULL/incomplete snapshots remain unknown; only validated empty snapshots are zero. USDT current price remains `1` as in the original page.

Platform retains its legacy facade and `/asset-distribution/lists`, `asset:distribution`, source-type/name filters, sorting and paging. `searchTenant($params, $tenantId)` is the trusted white-label entry: it validates the authenticated ID, overrides source type/ID and removes source keyword filters. Snapshot source type is `tenant`, not the user-directory type `white_label`.

Agent's `/api/asset-distribution/lists` requires an authenticated white-label account, active tenant/owned enabled site and exact `asset:distribution` permission. Merchant accounts cannot use it; neither account ID nor role-template ID becomes a tenant key. Client source/site/agent/tenant fields do not select scope. Existing report endpoints and role grants are unchanged.

Tests: `php tests/asset_distribution.php`; Platform `tests/Feature/Http/AssetDistributionTest.php` with isolated prediction bootstrap; Agent `WhiteLabelAssetDistributionTest` with isolated SQLite fixtures. No package publication is implied by local vendor synchronization.
