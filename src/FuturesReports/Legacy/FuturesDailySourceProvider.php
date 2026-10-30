<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\Legacy\Models\User;
use SixMm\Shared\FuturesReports\Legacy\Models\UserAccountChangeLog;
use SixMm\Shared\FuturesReports\Legacy\Models\UserPosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class FuturesDailySourceProvider
{
    private \Closure $executor;

    public function __construct(callable $executor, private ?string $database = null)
    {
        $this->executor = \Closure::fromCallable($executor);
    }

    private function select(string $sql): array
    {
        return ($this->executor)($sql);
    }

    public const SETTLE_ASSET = 'USDT';

    public const UNASSIGNED_SYMBOL = '__UNASSIGNED__';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function orderRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);

        $sql = <<<SQL
SELECT
    toString(o.agent_id) AS agent_id,
    toInt32(o.user_type) AS user_type,
    toString(o.user_id) AS user_id,
    o.symbol AS symbol,
    count() AS order_count,
    toString(sum(
        abs(toDecimal256OrZero(toString(o.quantity), 8)) *
        if(
            toDecimal256OrZero(toString(o.price), 8) != 0,
            abs(toDecimal256OrZero(toString(o.price), 8)),
            abs(toDecimal256OrZero(toString(o.avg_filled_price), 8))
        )
    )) AS order_amount
FROM {$database}.history_order_query AS o
WHERE 1 = 1

  AND o.user_id > 0
  AND notEmpty(o.symbol)
  AND o.created_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND o.created_at < toDateTime64('{$endUtc}', 3, 'UTC')
GROUP BY o.agent_id, o.user_type, o.user_id, o.symbol
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fillRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);
        $orderKeysSql = $this->tradedOrderKeysSql($database, $startUtc, $endUtc);

        // Restrict by immutable join keys, never by the order creation hour or mutable profile fields.
        $sql = <<<SQL
SELECT
    toString(ord.agent_id) AS agent_id,
    toInt32(ord.user_type) AS user_type,
    toString(o.user_id) AS user_id,
    o.symbol AS symbol,
    count() AS fill_count,
    countDistinct(o.order_id) AS filled_order_count,
    groupUniqArray(toString(o.order_id)) AS filled_order_ids,
    groupUniqArray(tuple(toString(if(o.trade_id != 0, o.trade_id, -o.fill_id)),
        toString(abs(toDecimal256OrZero(toString(o.notional), 16))))) AS execution_details,
    toString(max(o.traded_at)) AS last_trade_at,
    toString(sum(abs(toDecimal256OrZero(toString(o.notional), 16)))) AS trade_amount,
    toString(sum(abs(toDecimal256OrZero(toString(o.fee), 16)))) AS trading_fee,
    toString(sum(toDecimal256OrZero(toString(o.realized_pnl), 16))) AS realized_pnl,
    groupUniqArrayIf(
        toString(o.position_id),
        upperUTF8(o.place_type) = 'LIQUIDATION' AND o.position_id > 0
    ) AS liquidation_position_ids
FROM {$database}.history_trade_fills AS o
INNER JOIN (
    SELECT order_id, user_id, agent_id, user_type
    FROM {$database}.history_order_query FINAL
    WHERE (user_id, order_id) IN ({$orderKeysSql})

) AS ord
    ON ord.order_id = o.order_id
   AND ord.user_id = o.user_id
WHERE 1 = 1

  AND o.user_id > 0
  AND notEmpty(o.symbol)
  AND o.traded_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND o.traded_at < toDateTime64('{$endUtc}', 3, 'UTC')
GROUP BY ord.agent_id, ord.user_type, o.user_id, o.symbol
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    /**
     * User trading fees are independent of merchant commission settlement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function feeRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $agentIdList = $this->integerList($agentIds);
        if ($agentIdList === '') {
            return [];
        }
        if ($end->lessThanOrEqualTo($start)) {
            throw new RuntimeException('Invalid fee trade time range');
        }

        // Include retired contracts: their historical fees still belong in reports.
        $symbols = DB::table('symbol_config')
            ->whereRaw('UPPER(quote_asset) = ?', [self::SETTLE_ASSET])
            ->pluck('symbol')->all();
        if ($symbols === []) {
            throw new RuntimeException('Missing USDT contract metadata for fee trades');
        }
        $symbolList = implode(',', array_map(
            static fn ($symbol): string => "'" . str_replace(
                ["\\", "'"], ["\\\\", "\\'"], (string) $symbol
            ) . "'",
            $symbols
        ));
        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);

        // Replayed events must count once per user/order/execution, not once per ingestion.
        $sql = <<<SQL
SELECT
    toString(ord.agent_id) AS agent_id,
    toInt32(ord.user_type) AS user_type,
    toString(fills.user_id) AS user_id,
    fills.symbol AS symbol,
    'USDT' AS settle_asset,
    countIf(abs(fills.fee) > 0) AS fee_count,
    toString(ifNull(sumIf(abs(fills.notional), fills.role = 'MAKER'), 0)) AS maker_trade_amount,
    toString(ifNull(sumIf(abs(fills.notional), fills.role = 'TAKER'), 0)) AS taker_trade_amount,
    toString(ifNull(sumIf(abs(fills.fee), fills.role = 'MAKER'), 0)) AS maker_handling_fee,
    toString(ifNull(sumIf(abs(fills.fee), fills.role = 'TAKER'), 0)) AS taker_handling_fee,
    countIf(
        isNull(fills.fee) OR isNull(fills.notional) OR fills.notional <= 0
        OR fills.role NOT IN ('MAKER', 'TAKER') OR fills.valid_identity = 0
    ) AS invalid_count
FROM (
    SELECT
        latest.user_id AS user_id,
        latest.order_id AS order_id,
        tupleElement(latest.value, 1) AS symbol,
        tupleElement(latest.value, 2) AS role,
        tupleElement(latest.value, 3) AS notional,
        tupleElement(latest.value, 4) AS fee,
        tupleElement(latest.value, 5) AS valid_identity
    FROM (
        SELECT
            f.user_id,
            f.order_id,
            argMax(tuple(
                f.symbol, upperUTF8(f.role),
                toDecimal256OrNull(f.notional, 16),
                toDecimal256OrNull(f.fee, 16),
                f.order_id > 0 AND (f.trade_id > 0 OR f.fill_id > 0)
            ), tuple(f.ingested_at, f.wal_seq, f.partition_id, f.offset, f.row_index)) AS value
        FROM {$database}.history_trade_fills AS f
        WHERE f.user_id > 0
          AND f.symbol IN ({$symbolList})
          AND f.traded_at >= toDateTime64('{$startUtc}', 3, 'UTC')
          AND f.traded_at < toDateTime64('{$endUtc}', 3, 'UTC')
        GROUP BY f.user_id, f.order_id, if(f.trade_id > 0, f.trade_id, -f.fill_id)
    ) AS latest
) AS fills
INNER JOIN (
    SELECT order_id, user_id, agent_id, user_type
    FROM {$database}.history_order_query FINAL
    WHERE (user_id, order_id) IN (
        SELECT user_id, order_id FROM {$database}.history_trade_fills
        WHERE traded_at >= toDateTime64('{$startUtc}', 3, 'UTC')
          AND traded_at < toDateTime64('{$endUtc}', 3, 'UTC')
    )

) AS ord
    ON ord.order_id = fills.order_id
   AND ord.user_id = fills.user_id
WHERE 1 = 1

GROUP BY ord.agent_id, ord.user_type, fills.user_id, fills.symbol
FORMAT JSONEachRow
SQL;

        $rows = $this->select($sql);
        foreach ($rows as $row) {
            if ((int) ($row['invalid_count'] ?? 0) > 0) {
                throw new RuntimeException('Invalid fee trade amount, role or identity; report not published');
            }
        }
        return $rows;
    }

    /**
     * 按撤单完成时间统计撤单数量与剩余未成交金额。
     *
     * @return array<int, array<string, mixed>>
     */
    public function cancelRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);

        $sql = <<<SQL
SELECT
    toString(o.agent_id) AS agent_id,
    toInt32(o.user_type) AS user_type,
    toString(o.user_id) AS user_id,
    o.symbol AS symbol,
    count() AS cancel_order_count,
    toString(sum(
        abs(toDecimal256OrZero(toString(o.remaining_quantity), 8)) *
        if(
            toDecimal256OrZero(toString(o.price), 8) != 0,
            abs(toDecimal256OrZero(toString(o.price), 8)),
            abs(toDecimal256OrZero(toString(o.avg_filled_price), 8))
        )
    )) AS cancel_amount
FROM {$database}.history_order_query AS o
WHERE 1 = 1

  AND o.user_id > 0
  AND notEmpty(o.symbol)
  AND upperUTF8(o.final_status) IN (
      'CANCELED',
      'PARTIAL_FILLED_CANCELED',
      'PARTIALLY_FILLED_CANCELED'
  )
  AND o.updated_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND o.updated_at < toDateTime64('{$endUtc}', 3, 'UTC')
GROUP BY o.agent_id, o.user_type, o.user_id, o.symbol
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    /**
     * 模拟盘用户盈亏日报的平仓结算事实。
     *
     * 平仓委托按 order_id 去重；平仓金额、已实现盈亏及交易手续费按
     * 平仓成交累计。reduce_only/close_only 覆盖普通平仓，LIQUIDATION
     * 覆盖强平，realized_pnl 非零用于补充反向开仓中已经结算的部分。
     *
     * @return array<int, array<string, mixed>>
     */
    public function userPnlSettlementRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);
        $settleAsset = self::SETTLE_ASSET;
        $orderKeysSql = $this->tradedOrderKeysSql($database, $startUtc, $endUtc);

        $sql = <<<SQL
SELECT
    toString(ord.agent_id) AS agent_id,
    toInt32(ord.user_type) AS user_type,
    toString(f.user_id) AS user_id,
    '{$settleAsset}' AS settle_asset,
    countDistinct(f.order_id) AS close_order_count,
    groupUniqArray(toString(f.order_id)) AS close_order_ids,
    toString(sum(abs(toDecimal256OrZero(toString(f.notional), 16)))) AS close_amount,
    toString(sum(toDecimal256OrZero(toString(f.realized_pnl), 16))) AS realized_pnl,
    toString(sum(abs(toDecimal256OrZero(toString(f.fee), 16)))) AS trading_fee
FROM {$database}.history_trade_fills AS f
INNER JOIN (
    SELECT order_id, user_id, agent_id, user_type, reduce_only, close_only
    FROM {$database}.history_order_query FINAL
    WHERE (user_id, order_id) IN ({$orderKeysSql})

) AS ord
    ON ord.order_id = f.order_id
   AND ord.user_id = f.user_id
WHERE 1 = 1

  AND f.user_id > 0
  AND f.traded_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND f.traded_at < toDateTime64('{$endUtc}', 3, 'UTC')
  AND (
      ord.reduce_only = 1
      OR ord.close_only = 1
      OR upperUTF8(f.place_type) = 'LIQUIDATION'
      OR toDecimal256OrZero(toString(f.realized_pnl), 16) != 0
  )
GROUP BY ord.agent_id, ord.user_type, f.user_id
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    /**
     * 按撮合 trade_id 去重后的成交笔数与成交金额。
     *
     * 用户类型维度分别去重，同时返回 user_type=0 的全用户去重结果。
     * 手续费仍由 fillRows 按用户成交明细累计，避免丢失同一撮合双方的真实费用。
     *
     * @return array<int, array<string, mixed>>
     */
    public function executionSummaryRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);
        $orderKeysSql = $this->tradedOrderKeysSql($database, $startUtc, $endUtc);

        $sql = <<<SQL
SELECT
    toString(agent_id) AS agent_id,
    user_type,
    symbol,
    count() AS fill_count,
    groupUniqArray(toString(trade_id)) AS trade_ids,
    toString(sum(trade_amount)) AS trade_amount
FROM (
    SELECT
        agent_id,
        scope_user_type AS user_type,
        symbol,
        trade_id,
        max(trade_amount) AS trade_amount
    FROM (
        SELECT
            toInt64(ord.agent_id) AS agent_id,
            toInt32(ord.user_type) AS source_user_type,
            o.symbol AS symbol,
            if(toInt64(o.trade_id) != 0, toInt64(o.trade_id), -toInt64(o.fill_id)) AS trade_id,
            abs(toDecimal256OrZero(toString(o.notional), 16)) AS trade_amount
        FROM {$database}.history_trade_fills AS o
        INNER JOIN (
            SELECT order_id, user_id, agent_id, user_type
            FROM {$database}.history_order_query FINAL
            WHERE (user_id, order_id) IN ({$orderKeysSql})

        ) AS ord
            ON ord.order_id = o.order_id
           AND ord.user_id = o.user_id
        WHERE 1 = 1

          AND o.user_id > 0
          AND notEmpty(o.symbol)
          AND o.traded_at >= toDateTime64('{$startUtc}', 3, 'UTC')
          AND o.traded_at < toDateTime64('{$endUtc}', 3, 'UTC')
    ) AS filtered
    ARRAY JOIN arrayDistinct([source_user_type, toInt32(0)]) AS scope_user_type
    GROUP BY agent_id, scope_user_type, symbol, trade_id
)
GROUP BY agent_id, user_type, symbol
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function commissionRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        return DB::table((DB::getDriverName() === 'pgsql' ? 'public.' : '') . 'agent_commission_details as commissions')
            ->where('commissions.trade_time', '>=', $start->toDateTimeString())
            ->where('commissions.trade_time', '<', $end->toDateTimeString())
            ->whereNull('commissions.deleted_at')
            ->groupBy([
                'commissions.agent_id',
                'commissions.user_type',
                'commissions.platform_user_id',
                'commissions.symbol',
                'commissions.fee_asset',
            ])
            ->select([
                'commissions.agent_id',
                'commissions.user_type',
                'commissions.platform_user_id as user_id',
                'commissions.symbol',
                'commissions.fee_asset as settle_asset',
            ])
            ->selectRaw('COUNT(*) AS commission_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ABS(COALESCE(commissions.handling_fee, 0)) > 0 ' .
                'THEN 1 ELSE 0 END), 0) AS fee_count'
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(commissions.role_type) = 'maker' " .
                'THEN commissions.commission_amount ELSE 0 END), 0) AS maker_commission'
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(commissions.role_type) = 'taker' " .
                'THEN commissions.commission_amount ELSE 0 END), 0) AS taker_commission'
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(commissions.role_type) = 'maker' " .
                'THEN ABS(COALESCE(commissions.trade_value, 0)) ELSE 0 END), 0) ' .
                'AS maker_trade_amount'
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(commissions.role_type) = 'taker' " .
                'THEN ABS(COALESCE(commissions.trade_value, 0)) ELSE 0 END), 0) ' .
                'AS taker_trade_amount'
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(commissions.role_type) = 'maker' " .
                'THEN ABS(COALESCE(commissions.handling_fee, 0)) ELSE 0 END), 0) ' .
                'AS maker_handling_fee'
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN LOWER(commissions.role_type) = 'taker' " .
                'THEN ABS(COALESCE(commissions.handling_fee, 0)) ELSE 0 END), 0) ' .
                'AS taker_handling_fee'
            )
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * 已实现盈亏以成交明细为准，本查询只补资金费、强平费和余额变化。
     * 强平次数不能依赖费用流水（实际收费可能为零），由 liquidationRows 单独统计。
     *
     * @return array<int, array<string, mixed>>
     */
    public function riskRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $types = [
            UserAccountChangeLog::TYPE_HANDLING_FEE,
            UserAccountChangeLog::TYPE_FUNDING_FEE,
            UserAccountChangeLog::TYPE_LIQUIDATION,
            UserAccountChangeLog::TYPE_REALIZED_PNL,
        ];
        $symbolExpression = "COALESCE(NULLIF(logs.symbol, ''), '" .
            self::UNASSIGNED_SYMBOL .
            "')";

        return DB::table((DB::getDriverName() === 'pgsql' ? 'public.' : '') . 'user_account_change_log as logs')
            ->whereIn('logs.change_type', $types)
            ->where('logs.created_at', '>=', $start->toDateTimeString())
            ->where('logs.created_at', '<', $end->toDateTimeString())
            ->whereNull('logs.deleted_at')
            ->groupBy([
                'logs.agent_id',
                'logs.user_type',
                'logs.user_id',
                'logs.asset_type',
            ])
            ->groupByRaw($symbolExpression)
            ->select([
                'logs.agent_id',
                'logs.user_type',
                'logs.user_id',
                'logs.asset_type as settle_asset',
            ])
            ->selectRaw($symbolExpression . ' AS symbol')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN logs.change_type = ? THEN logs.amount ELSE 0 END), 0) AS funding_fee",
                [UserAccountChangeLog::TYPE_FUNDING_FEE]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN logs.change_type = ? THEN 1 ELSE 0 END), 0) ' .
                'AS funding_settlement_count',
                [UserAccountChangeLog::TYPE_FUNDING_FEE]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN logs.change_type = ? AND logs.amount > 0 ' .
                'THEN logs.amount ELSE 0 END), 0) AS funding_fee_income',
                [UserAccountChangeLog::TYPE_FUNDING_FEE]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN logs.change_type = ? AND logs.amount < 0 ' .
                'THEN ABS(logs.amount) ELSE 0 END), 0) AS funding_fee_expense',
                [UserAccountChangeLog::TYPE_FUNDING_FEE]
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN logs.change_type = ? THEN ABS(logs.amount) ELSE 0 END), 0) " .
                'AS liquidation_fee',
                [UserAccountChangeLog::TYPE_LIQUIDATION]
            )
            ->selectRaw('COALESCE(SUM(logs.amount), 0) AS margin_change')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * 资金费结算持仓金额以历史账户账本记录的结算数量与结算价格计算。
     *
     * 账变表负责资金收支口径，历史账本补充账变表中没有的持仓名义金额；
     * 两者按代理商、用户类型、用户和合约归并到同一事实行。
     *
     * @return array<int, array<string, mixed>>
     */
    public function fundingSettlementPositionRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);

        $sql = <<<SQL
SELECT
    toString(l.agent_id) AS agent_id,
    toInt32(l.user_type) AS user_type,
    toString(l.user_id) AS user_id,
    l.symbol AS symbol,
    'USDT' AS settle_asset,
    toString(sum(
        abs(toDecimal256OrZero(toString(l.position_quantity), 16)) *
        abs(toDecimal256OrZero(toString(l.funding_price), 16))
    )) AS funding_settlement_position_amount
FROM {$database}.history_account_ledgers AS l
WHERE 1 = 1

  AND l.user_id > 0
  AND notEmpty(l.symbol)
  AND lowerUTF8(l.biz_type) = 'funding_fee_settle'
  AND l.occurred_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND l.occurred_at < toDateTime64('{$endUtc}', 3, 'UTC')
GROUP BY l.agent_id, l.user_type, l.user_id, l.symbol
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    /**
     * 强平指标以强平成交事实为准，每行对应一个用户合约仓位。
     *
     * 强平仓位金额取该仓位窗口内首个强平订单的原始数量乘参考价格；
     * 市价单优先使用订单均价，仍为空时使用首笔成交价。
     *
     * @return array<int, array<string, mixed>>
     */
    public function liquidationRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);
        $orderKeysSql = $this->tradedOrderKeysSql($database, $startUtc, $endUtc, true);

        $sql = <<<SQL
SELECT
    toString(ord.agent_id) AS agent_id,
    toInt32(ord.user_type) AS user_type,
    toString(f.user_id) AS user_id,
    f.symbol AS symbol,
    toString(f.position_id) AS liquidation_position_id,
    toString(argMin(
        abs(toDecimal256OrZero(toString(ord.quantity), 16)) *
        if(
            abs(toDecimal256OrZero(toString(ord.price), 16)) > 0,
            abs(toDecimal256OrZero(toString(ord.price), 16)),
            if(
                abs(toDecimal256OrZero(toString(ord.avg_filled_price), 16)) > 0,
                abs(toDecimal256OrZero(toString(ord.avg_filled_price), 16)),
                abs(toDecimal256OrZero(toString(f.price), 16))
            )
        ),
        tuple(f.traded_at, f.order_id)
    )) AS liquidation_position_amount,
    toString(sum(abs(toDecimal256OrZero(toString(f.notional), 16))))
        AS liquidation_trade_amount,
    toString(sum(toDecimal256OrZero(toString(f.realized_pnl), 16)))
        AS liquidation_realized_pnl
FROM {$database}.history_trade_fills AS f
INNER JOIN (
    SELECT
        order_id,
        user_id,
        agent_id,
        user_type,
        quantity,
        price,
        avg_filled_price
    FROM {$database}.history_order_query FINAL
    WHERE (user_id, order_id) IN ({$orderKeysSql})

) AS ord
    ON ord.order_id = f.order_id
   AND ord.user_id = f.user_id
WHERE 1 = 1

  AND f.user_id > 0
  AND notEmpty(f.symbol)
  AND upperUTF8(f.place_type) = 'LIQUIDATION'
  AND f.traded_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND f.traded_at < toDateTime64('{$endUtc}', 3, 'UTC')
GROUP BY ord.agent_id, ord.user_type, f.user_id, f.symbol, f.position_id
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    private function tradedOrderKeysSql(string $database, string $startUtc, string $endUtc, bool $liquidation = false): string
    {
        $placeType = $liquidation ? "AND upperUTF8(place_type) = 'LIQUIDATION'" : '';
        return <<<SQL
SELECT user_id, order_id
FROM {$database}.history_trade_fills
WHERE user_id > 0
  AND notEmpty(symbol)
  AND traded_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND traded_at < toDateTime64('{$endUtc}', 3, 'UTC')
  {$placeType}
SQL;
    }

    /**
     * 穿仓指标以账户账本中的最终债务核销为准，每行对应一个唯一账本事件。
     *
     * 强平成交发生时 bankruptcy_loss 可能仍为零，最终穿仓损失会在稍后通过
     * history_account_ledgers.bankruptcy_loss_delta 入账，因此不能从成交表推导。
     *
     * @return array<int, array<string, mixed>>
     */
    public function bankruptcyRows(
        array $agentIds,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        [$startUtc, $endUtc] = $this->clickHouseBounds($start, $end);
        $agentIdList = $this->integerList($agentIds);

        $sql = <<<SQL
SELECT
    toString(l.agent_id) AS agent_id,
    toInt32(l.user_type) AS user_type,
    toString(l.user_id) AS user_id,
    l.symbol AS symbol,
    l.ledger_id AS bankruptcy_event_id,
    toString(max(abs(toDecimal256OrZero(toString(l.bankruptcy_loss_delta), 16))))
        AS bankruptcy_loss,
    toString(max(abs(toDecimal256OrZero(
        toString(l.liquidation_fee_final_shortfall), 16
    )))) AS uncollected_liquidation_fee,
    toString(max(abs(toDecimal256OrZero(
        toString(l.normal_fee_final_shortfall), 16
    )))) AS uncollected_trading_fee
FROM {$database}.history_account_ledgers AS l
WHERE 1 = 1

  AND l.user_id > 0
  AND notEmpty(l.symbol)
  AND notEmpty(l.ledger_id)
  AND (
      abs(toDecimal256OrZero(toString(l.bankruptcy_loss_delta), 16)) > 0
      OR abs(toDecimal256OrZero(
          toString(l.liquidation_fee_final_shortfall), 16
      )) > 0
      OR abs(toDecimal256OrZero(
          toString(l.normal_fee_final_shortfall), 16
      )) > 0
  )
  AND l.occurred_at >= toDateTime64('{$startUtc}', 3, 'UTC')
  AND l.occurred_at < toDateTime64('{$endUtc}', 3, 'UTC')
GROUP BY l.agent_id, l.user_type, l.user_id, l.symbol, l.ledger_id
FORMAT JSONEachRow
SQL;

        return $this->select($sql);
    }

    /**
     * 返回执行时刻的全量当前持仓，用于当日实时统计及UTC零点快照。
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentPositionRows(array $agentIds): array
    {
        if (empty($agentIds)) {
            return [];
        }

        $database = $this->historyDatabase();
        $agentIdList = $this->integerList($agentIds);
        $holding = UserPosition::STATUS_HOLDING;
        $liquidating = UserPosition::STATUS_LIQUIDATING;

        $sql = <<<SQL
SELECT
    toString(o.agent_id) AS agent_id,
    toInt32(o.user_type) AS user_type,
    toString(o.user_id) AS user_id,
    o.symbol AS symbol,
    toString(sumIf(abs(toDecimal256OrZero(toString(o.quantity), 16)), upperUTF8(o.position_side) = 'LONG')) AS long_position_quantity,
    toString(sumIf(abs(toDecimal256OrZero(toString(o.quantity), 16)), upperUTF8(o.position_side) = 'SHORT')) AS short_position_quantity,
    toString(sumIf(abs(
        toDecimal256OrZero(toString(o.entry_price), 16) *
        toDecimal256OrZero(toString(o.quantity), 16)
    ), upperUTF8(o.position_side) = 'LONG')) AS long_entry_amount,
    toString(sumIf(abs(
        toDecimal256OrZero(toString(o.entry_price), 16) *
        toDecimal256OrZero(toString(o.quantity), 16)
    ), upperUTF8(o.position_side) = 'SHORT')) AS short_entry_amount,
    toString(sum(abs(toDecimal256OrZero(toString(o.margin_price), 16)))) AS occupied_margin
FROM {$database}.current_position_query AS o
WHERE 1 = 1

  AND o.user_id > 0
  AND notEmpty(o.symbol)
  AND o.status IN ({$holding}, {$liquidating})
  AND abs(toDecimal256OrZero(toString(o.quantity), 16)) > 0
GROUP BY o.agent_id, o.user_type, o.user_id, o.symbol
FORMAT JSONEachRow
SQL;

        $rows = $this->select($sql);
        $prices = $this->positionMarketPrices(array_column($rows, 'symbol'));

        return array_map(static function (array $row) use ($prices): array {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            $longQuantity = (string) ($row['long_position_quantity'] ?? '0');
            $shortQuantity = (string) ($row['short_position_quantity'] ?? '0');
            $longEntryAmount = (string) ($row['long_entry_amount'] ?? '0');
            $shortEntryAmount = (string) ($row['short_entry_amount'] ?? '0');
            $markPrice = (string) ($prices[$symbol] ?? '0');
            $hasMarkPrice = bccomp($markPrice, '0', 16) > 0;
            $longAmount = $hasMarkPrice
                ? bcmul($longQuantity, $markPrice, 16)
                : $longEntryAmount;
            $shortAmount = $hasMarkPrice
                ? bcmul($shortQuantity, $markPrice, 16)
                : $shortEntryAmount;
            $unrealizedPnl = $hasMarkPrice
                ? bcadd(
                    bcsub($longAmount, $longEntryAmount, 16),
                    bcsub($shortEntryAmount, $shortAmount, 16),
                    16
                )
                : '0';

            return [
                'agent_id' => $row['agent_id'],
                'user_type' => $row['user_type'],
                'user_id' => $row['user_id'],
                'symbol' => $symbol,
                'closing_position_amount' => bcadd($longAmount, $shortAmount, 16),
                'long_position_amount' => $longAmount,
                'short_position_amount' => $shortAmount,
                'occupied_margin' => (string) ($row['occupied_margin'] ?? '0'),
                'unrealized_pnl' => $unrealizedPnl,
            ];
        }, $rows);
    }

    /**
     * @param array<int, mixed> $symbols
     * @return array<string, string>
     */
    private function positionMarketPrices(array $symbols): array
    {
        $symbols = array_values(array_unique(array_filter(array_map(
            static fn ($symbol): string => strtoupper(trim((string) $symbol)),
            $symbols
        ))));
        if ($symbols === []) {
            return [];
        }

        try {
            $redis = Redis::connection('listen');
            $values = $redis->pipeline(function ($pipe) use ($symbols): void {
                foreach ($symbols as $symbol) {
                    $pipe->hget('market_price:6mm:' . $symbol, 'last');
                }
            });
        } catch (Throwable $e) {
            return [];
        }

        $prices = [];
        foreach ($symbols as $index => $symbol) {
            $price = trim((string) ($values[$index] ?? ''));
            if ($price !== '' && is_numeric($price) && bccomp($price, '0', 16) > 0) {
                $prices[$symbol] = $price;
            }
        }

        return $prices;
    }

    private function historyDatabase(): string
    {
        $database = $this->database ?? (string) config('clickhouse.history.database', 'freedex_history');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $database)) {
            throw new RuntimeException('ClickHouse 历史数据库名称不合法');
        }

        return $database;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function clickHouseBounds(
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        return [
            $start->setTimezone('UTC')->format('Y-m-d H:i:s.v'),
            $end->setTimezone('UTC')->format('Y-m-d H:i:s.v'),
        ];
    }

    private function integerList(array $values): string
    {
        $values = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $value): bool => $value >= 0
        )));

        return implode(', ', $values);
    }
}
