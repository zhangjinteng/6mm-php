# Shared User Net PnL Report Implementation Plan

**Goal:** Reuse the Agent simulated user net PnL report in 6mm-ui and provide Platform with correctly scoped platform, white-label, and merchant daily/monthly rows, including platform-direct users.

**Architecture:** Publish a separate shared report from the existing validated hourly fact buckets after the current Agent summaries finish. Resolve user origins with the existing `SourceDirectory`/`ReportUserScope`, aggregate settlement identities and all-trade fees per source, and atomically replace one UTC day in a new public report table. Read through a bounded, server-scoped 6mm-php store; present through one 6mm-ui component with Agent and Platform request adapters. No new ClickHouse scan.

**Tech Stack:** PHP 8.2, Laravel 8 database API, PostgreSQL, Redis hourly facts, Vue 3, 6mm-ui, Vitest.

---

### Task 1: Shared aggregation, schema, and query

**Files:** `src/FuturesReports/UserNetPnlReportAggregator.php`, `UserNetPnlReportSchema.php`, `UserNetPnlReportStore.php`, `UserNetPnlReportPublisher.php`, `tests/user_net_pnl_reports.php`, `composer.json`.

1. Add failing fixture tests for platform-direct user inclusion, cross-tenant isolation, fee-only users, distinct settled users, decimal precision, status and atomic replacement.
2. Run `php tests/user_net_pnl_reports.php` and confirm the missing classes fail.
3. Implement the classes, using existing hourly `user_pnl_rows` and `rows` and reusing source-directory validation. Keep old daily rows intact on validation failure.
4. Run the new test and `composer test`; confirm both pass.

### Task 2: Shared UI

**Files:** `../6mm-ui/src/components/futures-user-net-pnl-report/*`, `../6mm-ui/src/components/index.ts`, `../6mm-ui/src/locales/{types,zh-CN,en-US}.ts`.

1. Add a failing Vitest test for day/month filters, platform-default source selection, source changes, and signed money display.
2. Implement `MmFuturesUserNetPnlReport` with `MmProTable`; keep source filters optional so Agent has the original compact controls.
3. Run targeted Vitest, typecheck and build.

### Task 3: Agent and Platform adapters

**Files:** `../agent_admin_view/src/views/mimic/pnl.vue`, Platform `app/Http/Controllers/Admin/UserNetPnlReportController.php`, `routes/admin.php`, `database/migrations/*`, `platform_admin_view/src/api/userNetPnlReport.ts`, `platform_admin_view/src/views/data_reports/user_net_pnl/index.vue`.

1. Test Platform route authorization and query response shape; verify an unrelated menu permission is rejected.
2. Wire the Agent page to the shared component using its existing authenticated endpoint. Wire Platform to the shared store and page with its own authenticated endpoint.
3. Add only an idempotent missing-menu migration if the existing route lacks a menu; do not change role grants.
4. Run PHP tests, Vue checks/builds and review the two pages visually with default/alternate filters.

### Task 4: Release boundary

1. Keep production scheduler and migrations unchanged until explicitly approved for rollout.
2. Publish shared package tags and update both application lockfiles only if the user authorizes release; otherwise report that local source is complete but pinned dependencies cannot yet load it.
3. Before a live rollout, create the new table, replay controlled UTC dates from existing hourly facts, compare merchant figures with Agent's current report, then activate the publisher.
