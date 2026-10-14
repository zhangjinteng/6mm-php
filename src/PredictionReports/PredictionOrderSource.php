<?php

declare(strict_types=1);

namespace SixMm\Shared\PredictionReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/** Read-only USDT player-order source; dates use the same UTC events as the legacy merchant daily report. */
final class PredictionOrderSource
{
    public function __construct(private ConnectionInterface $predictionDatabase) {}

    public function read(CarbonImmutable $day): array
    {
        $start = $day->startOfDay()->format('Y-m-d H:i:sP');
        $end = $day->addDay()->startOfDay()->format('Y-m-d H:i:sP');
        $prediction = $this->predictionDatabase->select(<<<'SQL'
SELECT
    funding_user_id AS user_id,
    CASE game_type WHEN 'HIGH_LOW' THEN 'high_low' WHEN 'UP_DOWN' THEN 'up_down' END AS play_type,
    UPPER(symbol) AS symbol,
    CASE result WHEN 'WIN' THEN 'win' WHEN 'LOSE' THEN 'loss'
        WHEN 'DRAW' THEN 'refund' WHEN 'CANCELED' THEN 'refund' END AS outcome,
    COUNT(*) AS order_count,
    COALESCE(SUM(stake_amount), 0)::text AS stake_amount,
    COALESCE(SUM(final_return), 0)::text AS return_amount
FROM prediction.prediction_order
WHERE created_at >= ?::timestamptz AND created_at < ?::timestamptz
  AND order_source = 'PLAYER' AND settlement_asset = 'USDT'
  AND result IN ('WIN', 'LOSE', 'DRAW', 'CANCELED')
  AND (result <> 'CANCELED' OR fund_status = 'REFUNDED')
  AND game_type IN ('HIGH_LOW', 'UP_DOWN')
GROUP BY funding_user_id, game_type, UPPER(symbol), result
SQL, [$start, $end]);
        $grid = $this->predictionDatabase->select(<<<'SQL'
WITH accepted_in_day AS (
    SELECT DISTINCT ON (order_id) order_id, created_at AS accepted_at
    FROM grid_contract.grid_order_state_logs
    WHERE to_status = 2 AND created_at >= ?::timestamptz AND created_at < ?::timestamptz
    ORDER BY order_id, created_at
)
SELECT
    orders.user_id,
    'grid' AS play_type,
    UPPER(orders.symbol) AS symbol,
    CASE orders.status WHEN 5 THEN 'win' WHEN 6 THEN 'loss' WHEN 7 THEN 'refund' END AS outcome,
    COUNT(*) AS order_count,
    (COALESCE(SUM(orders.stake_amount_units), 0)::numeric / 100000000)::text AS stake_amount,
    (COALESCE(SUM(CASE WHEN orders.status = 5 THEN orders.locked_gross_payout_units
        WHEN orders.status = 7 THEN orders.stake_amount_units ELSE 0 END), 0)::numeric / 100000000)::text AS return_amount
FROM accepted_in_day AS accepted
JOIN grid_contract.grid_orders AS orders ON orders.order_id = accepted.order_id
WHERE orders.settlement_asset = 'USDT' AND orders.status IN (5, 6, 7)
  AND NOT EXISTS (
      SELECT 1 FROM grid_contract.grid_order_state_logs AS earlier
      WHERE earlier.order_id = accepted.order_id AND earlier.to_status = 2
        AND earlier.created_at < accepted.accepted_at
  )
GROUP BY orders.user_id, UPPER(orders.symbol), orders.status
SQL, [$start, $end]);
        return array_merge(array_map(static fn (object $row): array => (array) $row, $prediction),
            array_map(static fn (object $row): array => (array) $row, $grid));
    }
}
