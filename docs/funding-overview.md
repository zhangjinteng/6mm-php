# Funding overview

`SixMm\Shared\FundingOverview\FundingOverviewListService` reads the current USDT funding and contract balances. It never writes balances and needs no aggregation job or new table.

```php
$result = (new FundingOverviewListService($assetDb, $mainDb, 'UTC'))
    ->search(new FundingOverviewListQuery(page: 1, pageSize: 20), $userScope);
```

- `userScope` is mandatory and applies before balances, filtering, counts and pagination. Agent passes its main/child agent IDs plus the authenticated main-agent ID as the third argument. Platform passes its existing source-filter scope and omits that argument, resolving `agents.pid` for child users and owner `0` for direct users.
- Funding wallets must be ACTIVE, assets ENABLED and asset code USDT. Match both internal user ID and the expected wallet agent ID; a wallet with a different tenant must not contribute.
- `funding_balance = (available_amount + held_amount) / 10^internal_scale`.
- `contract_equity = SUM(user_assets.wallet_balance)`; `contract_occupied = SUM(frozen_balance)` for non-deleted USDT asset rows. This preserves the existing Agent field definitions and does not add unrealized PnL or count occupation again.
- `total_assets = funding_balance + contract_equity`. All arithmetic and sorting preserve decimal strings, never binary floats.
- The last-change field is the latest funding-balance/contract-asset update, not a ledger event timestamp. UTC boundaries are supplied by hosts; display uses the injected host timezone.
- `user_id` is the public display UID (internal ID fallback); `platform_user_id` is the internal detail-dialog identity. Do not interchange them.
- Sorting covers the full result before pagination. Cross-database computed-amount ordering still reads the matched user set in batches; very large all-source result sets should use narrow filters or a separately designed materialized read model, not page-local sorting.

Run `php tests/funding_overview.php`. Applications must install a future shared-package release containing this namespace before deployment; do not upgrade version constraints to a tag that has not been published.
