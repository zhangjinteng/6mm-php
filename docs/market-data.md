# Shared market data (read only)

`MarketCatalog::preview()` accepts a host-authorized query and funding intervals. It filters and sorts before pagination, preserves the funding countdown, and never initializes tenant configuration.

`MarketTradeStats::query()` accepts already-authorized symbols and a ClickHouse select callback. Its 24-hour volume/quantity are public, market-wide totals per symbol, not tenant or user reports. Empty catalogs skip upstream queries. SQL identifiers/symbols are validated.

`MarketHistory` receives connection and symbol-order closures. The host's named `pg` connection provides the existing `public.kline_{market,mark,index}_{interval}` views. Decimal strings, UTC windows, count/paging and Platform's legacy snapshot metrics are preserved. Queries run in a read-only transaction with a five-second statement timeout.

The optional allowed-symbol array is a security boundary: `null` preserves the Platform scope; `[]` returns no rows without connecting upstream. Nonempty scopes filter both COUNT and page queries in SQL, and disable non-price snapshot metrics. Never pass `null` for an empty tenant catalog.

Agent resolves the first enabled site belonging to the authenticated white-label account and intersects platform eligibility with the site's effective enabled/trading flags. Client-supplied tenant/site/agent IDs do not select the scope. Merchant identities cannot access these new white-label endpoints. Host middleware and controllers retain the existing `symbol:preview` / `funding_rate:other` permission checks.

Agent must configure `PG_HOST`, `PG_PORT`, `PG_DATABASE`, `PG_USERNAME`, `PG_PASSWORD` (and optionally `PG_SSLMODE`) for the market database. Use a read-only DB user. Do not silently fall back to the business DB. ClickHouse uses the host's existing history connection. No schema migration, menu writes or private credentials are supplied by this library.

Verification: `php tests/market_data.php`. Shared UI components accept typed API callbacks, symbol-display slots and a reactive public ticker map; websocket lifecycle remains host-owned. Source changes need a coordinated shared-package release before deployment; local vendor/node_modules copies are for verification only.
