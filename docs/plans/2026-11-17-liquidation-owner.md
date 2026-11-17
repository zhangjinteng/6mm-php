# Liquidation ownership Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Apply CK historical ownership to liquidation lists, totals, details, and order-fill drill-downs in both administration hosts.

**Architecture:** Reuse the validated `TradeFillOwnerScope` and total-admin owner directory rather than deriving authorization from current users. Keep the existing position-based Platform list, fill-based Agent aggregation, liquidation detection, fee and ledger calculations unchanged. Only enrich page identities after CK authorization.

**Tech Stack:** PHP 8.2, Laravel 8, shared 6mmphp, ClickHouse, SQLite/HTTP-fake PHPUnit tests, Vue request adapter.

---

### Task 1: Shared ownership-aware drill-down and identity enrichment

- Modify `src/Liquidations/LiquidationTradeListQueryService.php`: add an owner-aware entry point with `f.place_type='LIQUIDATION'`, owner predicates on `f`, and the existing order grouping/pagination.
- Modify `src/Liquidations/LiquidationUserContextService.php`: page-only historical identity hydration and keyword lookup; never drop moved/deleted profiles or overwrite CK owner IDs.
- Verify `tests/liquidation_owners.php`, including empty scopes, platform zero/zero, broad total-admin categories and detail/count/list predicate consistency.

### Task 2: Agent authenticated exact owners

- Modify Agent `UserAccessScope`, `LiquidationController`, and `ClickHouseHistoryService` to pass the server-derived first enabled white-label site or main merchant pair.
- Preserve array-based legacy adapters and their existing tests. Add exact-owner branches to the active routes.
- Test list, detail and order drill-downs with wrong request IDs, child logins, missing sites, moved users, and HTTP-fake SQL assertions. Existing liquidation fee/ledger regressions must pass.

### Task 3: Platform CK categories

- Modify Platform `UsersSrv::forcedListShared` and `forcedTradeList` to use the shared owner directory and the same predicate for counts and page rows.
- Select CK owner columns and enrich sources from CK IDs, not current `users` ownership.
- Forward current source filters to the drill-down request in `forced.vue` and its controller.
- Retain legacy helpers for legacy routes, and leave fees/strong-liquidation status logic untouched.

### Task 4: Verify and synchronize

- Run isolated shared and host tests, PHP lint, frontend compile/request regression tests, and `git diff --check`.
- Synchronize only changed shared files into local host dependencies, preserving unrelated work and dependency versions.
- Read-only live CK smoke tests: category union totals, exact-owner lookup, out-of-scope detail/drill-down denial. No migrations, database writes, historical updates, or package publication.
