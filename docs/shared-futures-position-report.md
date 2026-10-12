# Shared futures position report

`PositionReportPublisher` is part of the same platform-only five-minute producer as the trade report. It reads the live per-user/symbol position snapshot already captured by `AgentFuturesHourlyFactCoordinator`, attributes each row through `users.home_site_id` and the source directory, and atomically replaces that UTC day's report. The merchant and platform APIs use `PositionReportStore::search`; merchant scope is always server-derived.

At UTC 00:00, the producer records a separate boundary snapshot and publishes it as the **previous** day's completed position report. The separate Redis key prevents the current day's subsequent live refresh from overwriting the boundary snapshot used by the 00:10 finalization run. The scheduled live refresh intentionally skips 00:00 so the boundary capture gets the producer lock.

This is a **stock**, not a flow: monthly values use the last available daily snapshot, with no cross-day sum. Average leverage is total position notional divided by occupied margin. User counts are distinct per source/symbol snapshot. `status=2` flags incomplete attribution.

Historical days without a real UTC boundary snapshot are not manufactured from present-day positions. After the schema migration, `platform:backfill-futures-position-reports --from=YYYY-MM-DD --to=YYYY-MM-DD` can restore merchant-only historical rows from `admin_agent_futures_user_symbol_daily` where `position_snapshot_at` exactly matches the following UTC midnight. It does not create historical platform or white-label totals, which the legacy merchant-only facts cannot establish. Existing shared rows are never overwritten by backfill.

Deployment order: release/install the updated `6mm-php`, run the platform migration, deploy the platform producer/API, then release/install the updated `6mm-ui` in both frontends. The merchant API reads the same shared report table and does not schedule another producer. The old merchant daily endpoint remains for other report kinds.
