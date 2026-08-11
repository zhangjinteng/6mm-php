<?php

declare(strict_types=1);

namespace SixMm\Shared\Liquidations;

use InvalidArgumentException;
use SixMm\Shared\Pagination\PageResult;

final class LiquidationTradeListQueryService
{
    public function __construct(
        private LiquidationTradeQueryExecutor $executor,
        private string $database = 'freedex_history',
        private string $timezone = 'UTC'
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->database)) {
            throw new InvalidArgumentException('Invalid ClickHouse database identifier.');
        }

        $this->timezone = trim($this->timezone) !== '' ? trim($this->timezone) : 'UTC';
    }

    /**
     * @param array<int, int|string> $agentIds
     * @return PageResult<array<string, mixed>>
     */
    public function search(LiquidationTradeListQuery $query, array $agentIds): PageResult
    {
        $agentIds = array_values(array_unique(array_filter(
            array_map('intval', $agentIds),
            static fn(int $agentId): bool => $agentId > 0
        )));

        if ($query->positionId() <= 0 || $agentIds === []) {
            return new PageResult([], 0, $query->page(), $query->pageSize());
        }

        $positionId = $query->positionId();
        $agentIdList = implode(', ', $agentIds);
        $whereSql = "
            upperUTF8(f.place_type) = 'LIQUIDATION'
              AND f.position_id = {$positionId}
              AND o.agent_id IN ({$agentIdList})
              AND o.user_type IN (1, 2)
        ";
        $groupBy = 'f.user_id, f.position_id, f.order_id';
        $countSql = "
            SELECT count() AS aggregate
            FROM (
                SELECT f.order_id
                FROM {$this->database}.history_trade_fills AS f
                INNER JOIN {$this->database}.history_order_query AS o
                    ON o.order_id = f.order_id
                   AND o.user_id = f.user_id
                WHERE {$whereSql}
                GROUP BY {$groupBy}
            )
            FORMAT JSONEachRow
        ";
        $total = (int) ($this->executor->select($countSql)[0]['aggregate'] ?? 0);

        if ($total === 0) {
            return new PageResult([], 0, $query->page(), $query->pageSize());
        }

        $quantityExpression = 'sum(abs(toDecimal256OrZero(f.quantity, 18)))';
        $notionalExpression = 'sum(abs(toDecimal256OrZero(f.notional, 18)))';
        $averagePriceExpression = "{$notionalExpression} / nullIf({$quantityExpression}, 0)";
        $limit = $query->pageSize();
        $offset = ($query->page() - 1) * $limit;
        $timezone = $this->quote($this->timezone);
        $listSql = "
            SELECT
                toString(f.position_id) AS position_id,
                toString(f.order_id) AS order_id,
                lowerUTF8(any(f.side)) AS side,
                toString(ifNull({$averagePriceExpression}, 0)) AS price,
                toString({$quantityExpression}) AS quantity,
                toString({$notionalExpression}) AS trade_value,
                upperUTF8(any(f.role_type)) AS role_type,
                toString(sum(abs(toDecimal256OrZero(f.fee, 18)))) AS handling_fee,
                formatDateTime(toTimeZone(max(f.traded_at), {$timezone}), '%F %T') AS trade_time,
                count() AS fill_count
            FROM {$this->database}.history_trade_fills AS f
            INNER JOIN {$this->database}.history_order_query AS o
                ON o.order_id = f.order_id
               AND o.user_id = f.user_id
            WHERE {$whereSql}
            GROUP BY {$groupBy}
            ORDER BY min(f.traded_at) ASC, f.order_id ASC
            LIMIT {$limit} OFFSET {$offset}
            FORMAT JSONEachRow
        ";

        return new PageResult(
            $this->executor->select($listSql),
            $total,
            $query->page(),
            $query->pageSize()
        );
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
