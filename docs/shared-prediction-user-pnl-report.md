# Shared prediction user PnL report

The platform is the only publisher of `public.admin_prediction_user_pnl_daily`. Both admin apps read it through `PredictionUserPnlStore`; merchant source IDs are derived from the authenticated account, never request parameters.

The report uses UTC calendar days and USDT player orders. High/Low and Up/Down orders are counted by `prediction.prediction_order.created_at`; grid orders use their first accepted state (`to_status = 2`). Only settled win/loss/refund orders contribute. A canceled prediction order counts as refund only when `fund_status = REFUNDED`. Grid stakes and payouts are converted from 1e8 units. Robot users are excluded. A user belongs to a merchant only when its agent chain resolves to an active root merchant in the same home site; tenant attribution follows the user's home site, while platform includes all valid-site users including directly owned users. Missing or conflicting origins mark affected rollups incomplete.

All dimensions are generated from one source scan: all plays / individual play, all symbols / individual symbol, and merchant / tenant / platform. User win/loss/even classification is determined from that user's net PnL inside each dimension before source-level aggregation. Refund stakes contribute to total stake and return but not valid stake. Payout rate is total return / total stake × 100. `platform_pnl = -user_net_pnl`. Source rows overlap and must never be added together.

The UI intentionally offers daily reporting only. Summing daily distinct-user counts into a month would be incorrect; monthly reporting needs separate cross-day user identities.

Deployment order:

1. Publish and install the new 6mm-php and 6mm-ui builds in platform and merchant apps.
2. Run the platform migration `2026_10_14_000002_create_shared_prediction_user_pnl_report.php` on the intended database.
3. Verify read access to the `predict_report` connection and run `php artisan platform:refresh-prediction-user-pnl-report --date=YYYY-MM-DD` for desired history.
4. Set `PREDICTION_USER_PNL_REPORT_ENABLED=true` in the platform app and refresh its config cache. The scheduled producer refreshes today every five minutes and yesterday at 00:10 UTC.

Neither package publication nor migrations are performed automatically by this source change.
