<?php

declare(strict_types=1);

namespace SixMm\Shared\UserPredictions;

use Illuminate\Database\ConnectionInterface;

final class UserPredictionReportService
{
    private const PLAY_ALL = 'all';
    private const PLAY_GRID = 'grid';
    private const PLAY_HIGH_LOW = 'high_low';
    private const PLAY_UP_DOWN = 'up_down';

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * @param array<int, int|string> $userIds
     * @return array{lists: array<int, array<string, mixed>>, count: int}
     */
    public function search(
        array $userIds,
        string $playType,
        int $page,
        int $pageSize,
        ?string $startTime,
        ?string $endTimeExclusive,
        string $orderBy,
        string $orderDirection
    ): array {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        if ($userIds === []) {
            return ['lists' => [], 'count' => 0];
        }

        $playType = in_array($playType, [
            self::PLAY_ALL,
            self::PLAY_GRID,
            self::PLAY_HIGH_LOW,
            self::PLAY_UP_DOWN,
        ], true) ? $playType : self::PLAY_ALL;

        [$sourceSql, $sourceBindings] = $this->sourceSql($playType, $this->postgresArray($userIds));
        $aggregateSql = <<<SQL
            WITH source AS (
                {$sourceSql}
            )
            SELECT
                user_id,
                COUNT(*) FILTER (WHERE is_pending) AS pending_orders,
                COALESCE(SUM(stake_amount) FILTER (WHERE is_pending), 0) AS pending_amount,
                COUNT(*) FILTER (
                    WHERE event_at >= CURRENT_TIMESTAMP - INTERVAL '30 days'
                      AND outcome IN ('win', 'loss', 'refund')
                ) AS orders_30d,
                COUNT(*) FILTER (
                    WHERE event_at >= CURRENT_TIMESTAMP - INTERVAL '30 days' AND outcome = 'win'
                ) AS win_orders,
                COUNT(*) FILTER (
                    WHERE event_at >= CURRENT_TIMESTAMP - INTERVAL '30 days' AND outcome = 'loss'
                ) AS lose_orders,
                COUNT(*) FILTER (
                    WHERE event_at >= CURRENT_TIMESTAMP - INTERVAL '30 days' AND outcome = 'refund'
                ) AS refund_orders,
                COALESCE(SUM(stake_amount) FILTER (
                    WHERE event_at >= CURRENT_TIMESTAMP - INTERVAL '30 days'
                      AND outcome IN ('win', 'loss', 'refund')
                ), 0) AS stake_30d,
                COALESCE(SUM(return_amount) FILTER (
                    WHERE event_at >= CURRENT_TIMESTAMP - INTERVAL '30 days'
                      AND outcome IN ('win', 'loss', 'refund')
                ), 0) AS return_30d,
                COALESCE(SUM(return_amount - stake_amount) FILTER (
                    WHERE event_at >= CURRENT_TIMESTAMP - INTERVAL '30 days'
                      AND outcome IN ('win', 'loss', 'refund')
                ), 0) AS net_profit_30d,
                MAX(event_at) AS last_prediction_at
            FROM source
            GROUP BY user_id
        SQL;

        $conditions = [];
        $filterBindings = [];
        if ($startTime !== null && trim($startTime) !== '') {
            $conditions[] = 'report.last_prediction_at >= ?';
            $filterBindings[] = $startTime;
        }
        if ($endTimeExclusive !== null && trim($endTimeExclusive) !== '') {
            $conditions[] = 'report.last_prediction_at < ?';
            $filterBindings[] = $endTimeExclusive;
        }
        $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $countRow = $this->connection->selectOne(
            "SELECT COUNT(*) AS aggregate FROM ({$aggregateSql}) AS report{$whereSql}",
            array_merge($sourceBindings, $filterBindings)
        );

        $sortable = [
            'pending_orders',
            'pending_amount',
            'orders_30d',
            'win_orders',
            'lose_orders',
            'refund_orders',
            'stake_30d',
            'return_30d',
            'net_profit_30d',
            'last_prediction_at',
        ];
        $orderBy = in_array($orderBy, $sortable, true) ? $orderBy : 'last_prediction_at';
        $orderDirection = strtolower($orderDirection) === 'asc' ? 'ASC' : 'DESC';
        $offset = ($page - 1) * $pageSize;

        $rows = $this->connection->select(
            "SELECT * FROM ({$aggregateSql}) AS report{$whereSql} "
                . "ORDER BY {$orderBy} {$orderDirection}, user_id ASC LIMIT ? OFFSET ?",
            array_merge($sourceBindings, $filterBindings, [$pageSize, $offset])
        );

        return [
            'lists' => array_map(static fn (object $row): array => (array) $row, $rows),
            'count' => (int) ($countRow->aggregate ?? 0),
        ];
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function sourceSql(string $playType, string $userIds): array
    {
        $queries = [];
        $bindings = [];

        if (in_array($playType, [self::PLAY_ALL, self::PLAY_GRID], true)) {
            $queries[] = $this->gridSourceSql();
            $bindings[] = $userIds;
        }

        if (in_array($playType, [self::PLAY_ALL, self::PLAY_HIGH_LOW, self::PLAY_UP_DOWN], true)) {
            $queries[] = $this->predictionSourceSql($playType);
            $bindings[] = $userIds;
        }

        return [implode("\nUNION ALL\n", $queries), $bindings];
    }

    private function gridSourceSql(): string
    {
        return <<<'SQL'
            SELECT
                orders.user_id,
                active.accepted_at AS event_at,
                orders.status NOT IN (5, 6, 7) AS is_pending,
                CASE orders.status
                    WHEN 5 THEN 'win'
                    WHEN 6 THEN 'loss'
                    WHEN 7 THEN 'refund'
                    ELSE NULL
                END AS outcome,
                orders.stake_amount_units::numeric / 100000000 AS stake_amount,
                CASE orders.status
                    WHEN 5 THEN orders.locked_gross_payout_units::numeric / 100000000
                    WHEN 7 THEN orders.stake_amount_units::numeric / 100000000
                    ELSE 0::numeric
                END AS return_amount
            FROM grid_contract.grid_orders AS orders
            INNER JOIN LATERAL (
                SELECT MIN(created_at) AS accepted_at
                FROM grid_contract.grid_order_state_logs
                WHERE order_id = orders.order_id
                  AND to_status = 2
            ) AS active ON active.accepted_at IS NOT NULL
            WHERE orders.user_id = ANY(?::bigint[])
              AND orders.settlement_asset = 'USDT'
        SQL;
    }

    private function predictionSourceSql(string $playType): string
    {
        $gameCondition = match ($playType) {
            self::PLAY_HIGH_LOW => " AND orders.game_type = 'HIGH_LOW'",
            self::PLAY_UP_DOWN => " AND orders.game_type = 'UP_DOWN'",
            default => '',
        };

        return <<<SQL
            SELECT
                orders.funding_user_id AS user_id,
                orders.created_at AS event_at,
                (
                    orders.business_status = 'ACTIVE'
                    AND orders.fund_status = 'DEBITED'
                    AND orders.result = 'PENDING'
                ) AS is_pending,
                CASE
                    WHEN orders.result = 'WIN' THEN 'win'
                    WHEN orders.result = 'LOSE' THEN 'loss'
                    WHEN orders.result IN ('DRAW', 'CANCELED')
                         AND orders.fund_status = 'REFUNDED' THEN 'refund'
                    ELSE NULL
                END AS outcome,
                orders.stake_amount::numeric AS stake_amount,
                COALESCE(orders.final_return, 0)::numeric AS return_amount
            FROM prediction.prediction_order AS orders
            WHERE orders.user_id = ANY(?::text[])
              AND orders.order_source = 'PLAYER'
              AND orders.settlement_asset = 'USDT'
              AND orders.fund_status <> 'REJECTED'
              {$gameCondition}
        SQL;
    }

    /** @param array<int, int> $values */
    private function postgresArray(array $values): string
    {
        return '{' . implode(',', $values) . '}';
    }
}
