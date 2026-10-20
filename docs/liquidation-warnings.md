# Shared liquidation warnings

`LiquidationWarningListService` combines `GetRiskPositions` with `current_position_query` and the shared user identity context. Hosts supply a risk-batch callback and ClickHouse selector. It never opens a trade, closes a position or modifies configuration while reading.

- Scope comes from the host's authenticated context (`AgentIdsScope` for a main merchant and children; Platform uses its existing source scope).
- Risk matching uses user, symbol, margin mode **and direction**. Opposite-side positions must not enter the warning list through a user/symbol-only match.
- Full batches reread the trailing user; an unadvanceable cursor fails rather than returning silently truncated data.
- Risk rate, PnL, margin balance and estimated liquidation price come from TradeKernel. A missing liquidation estimate is not replaced with the bankruptcy price.
- Rule APIs expose percentages; storage and RPC thresholds use fractional ratios. `LiquidationWarningRulesService` resolves the exact `(home_site_id, agent_id, liquidation_warning_rules)` record, or the complete `(0,0)` rule when it is absent. Old percentage records and legacy `system_config` defaults remain readable.
- Saves update only the target source, preserve unrelated JSON keys, and require the scoped RPC callback to acknowledge before the database transaction commits. RPC failures must not be presented as successful saves.

## Host integration and deployment

Agent exposes authenticated GET `/api/liqWarning/lists`, GET `/api/liqWarning/rules` and POST `/api/liqWarning/rules`. The write endpoint accepts only the three rule fields; IDs are resolved from the main merchant record, not request input. Super administrators can edit; other roles require the enabled `liqWarning:edit` permission. No menu or permission data is automatically inserted.

Deploy updated shared packages before the host code. Local ignored dependency copies may be synchronized for development; this does not publish a release or update lockfile constraints.

The upstream TradeKernel ACL must authorize the existing `agent_admin` service identity for:

- `GetRiskPositions` (read leader/follower routing follows the SDK)
- `GetRiskNotificationConfig` (write-leader read of the **target** scope version)
- `UpdateRiskNotificationConfig` (merchant-scoped rule update)

Do not impersonate Platform's `admin` identity to bypass upstream access control. At local integration time `GetRiskPositions` returned `method_not_allowed`; no upstream ACL was changed and no real configuration save was executed.

Run `php tests/liquidation_warnings.php` and the host regression tests with fake RPC/SQLite to verify saves without affecting deployed services.

## Verification (2026-10-20)

- Shared PHP regression suite: passed, including the new warning tests.
- Shared UI: 143 suites / 669 tests passed; the warning-specific 5 tests and typecheck were rerun after the fill-height/default-prop fix. Library build passed.
- Agent: 7 PHP integration tests / 25 assertions and 2 Vue integration tests passed; frontend typecheck passed.
- Platform: 32 rule-configuration tests / 166 assertions, 28 legacy risk-query tests / 205 assertions, and 37 Vue/helper/dialog tests passed. The shared page was verified in the local browser.
- Platform whole-project typecheck still reports errors in unchanged editor/export/agent-margin/decoration files; no error was reported in the warning integration files.
- No package release, real rule save, upstream ACL modification or position-close action was performed.
