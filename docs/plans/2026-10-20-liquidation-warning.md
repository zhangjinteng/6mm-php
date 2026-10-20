# Shared Liquidation Warning Implementation Plan

> **For Claude:** Follow the Code workflow: implement each task and verify it before proceeding.

**Goal:** Reuse the Platform liquidation-warning query and UI in Agent without widening merchant access or changing trading settings.

**Architecture:** Shared PHP owns risk RPC pagination, scoped ClickHouse selection, rule normalization and risk hydration. Shared Vue owns the warning table, risk formatting and rule dialog; hosts provide requests, identity/source slots and navigation. Platform retains its configuration write gateway. Agent reads an exact main-merchant/site record first, falls back to platform defaults when absent, and can save its own rules with an authorized scoped RPC acknowledgement.

**Tech Stack:** PHP 8.2, Illuminate Database, TradeKernel gRPC adapters, ClickHouse, Vue 3, TypeScript, 6mm-ui, Vitest.

---

### Task 1: Shared PHP domain and tests
- Add `src/LiquidationWarnings/LiquidationWarningRulesService.php` and `LiquidationWarningListService.php`.
- Add SQLite/fake RPC/fake ClickHouse tests for fractional and legacy rule values, exact risk identity, cursor paging, merchant isolation, filters, sorting, empty/error results and public UID hydration.
- Run `php tests/liquidation_warnings.php` and the package regression suite.

### Task 2: Shared Vue warning table
- Add `src/components/liquidation-warning-table/` with table, rules dialog, pure formatting helpers, public types and exports.
- Preserve existing MmPositionTable query/table/slot behavior and support caller-provided columns, source fields, permission-controlled rule editing and read-only accounts.
- Test boundary values, row tags, loading/error/retry, cancellation, read-only rules and slots with Vitest; run typecheck/build.

### Task 3: Host adapters
- Replace Platform shared-warning query path and component usage with the shared services; retain legacy endpoints and configuration write permission checks.
- Implement authenticated Agent `/liqWarning/lists` and `/liqWarning/rules` GET endpoints using server-derived main/child agent IDs. Replace the old Element Plus view with the shared table.
- Add authorized Agent POST `/liqWarning/rules`, rejecting caller-supplied agent/site IDs. Add host regression tests. Do not change menu data, execute a close action, save real configuration, or publish packages.

### Task 4: Local integration verification
- Use local-only dependency resolution for new shared sources without changing released package version constraints.
- Run package and host tests and inspect both existing local pages through the browser. Test query/reset/read-only rules only; no financial actions or configuration saves.
- Record verification limitations and delivery paths.
