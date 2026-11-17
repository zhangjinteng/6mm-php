<?php

declare(strict_types=1);

namespace SixMm\Shared\TradeFills;

use Closure;
use InvalidArgumentException;

/** Shared CK query construction for Platform and Agent; enrichment is page-only. */
final class TradeFillOwnerQueryService
{
    private Closure $select;
    private Closure $quote;
    private ?Closure $timeRange;

    public function __construct(callable $select, private string $database, callable $quote, private string $timezone = 'UTC', ?callable $timeRange = null)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $database)) throw new InvalidArgumentException('Invalid history database.');
        $this->select = Closure::fromCallable($select); $this->quote = Closure::fromCallable($quote);
        $this->timeRange = $timeRange === null ? null : Closure::fromCallable($timeRange);
    }

    public function rows(TradeFillListQuery $query, TradeFillOwnerScope $scope, array $keywordUserIds = []): array
    {
        if ($scope->isEmpty()) return [];
        $columns = ['trade_time'=>'o.traded_at', 'order_id'=>'o.order_id', 'position_id'=>'o.position_id', 'symbol'=>'o.symbol',
            'side'=>'o.side', 'quantity'=>'toDecimal128OrZero(o.quantity, 18)', 'price'=>'toDecimal128OrZero(o.price, 18)',
            'trade_value'=>'toDecimal128OrZero(o.notional, 18)', 'handling_fee'=>'abs(toDecimal128OrZero(o.fee, 18))',
            'role_type'=>'o.role_type', 'realized_pnl'=>'toDecimal128OrZero(o.realized_pnl, 18)'];
        $sort = $columns[$query->orderBy()]; $direction = strtoupper($query->orderDirection());
        $offset = ($query->page() - 1) * $query->pageSize();
        return ($this->select)($this->selectSql() . ' WHERE ' . $this->where($query, $scope, $keywordUserIds)
            . " ORDER BY {$sort} {$direction}, o.traded_at DESC, o.fill_id DESC LIMIT {$query->pageSize()} OFFSET {$offset} FORMAT JSONEachRow");
    }

    public function count(TradeFillListQuery $query, TradeFillOwnerScope $scope, array $keywordUserIds = []): int
    {
        if ($scope->isEmpty()) return 0;
        return (int) (($this->select)("SELECT count() AS total FROM {$this->database}.history_trade_fills AS o WHERE "
            . $this->where($query, $scope, $keywordUserIds) . ' FORMAT JSONEachRow')[0]['total'] ?? 0);
    }

    public function detail(int $fillId, TradeFillOwnerScope $scope): array
    {
        if ($fillId <= 0 || $scope->isEmpty()) return [];
        return ($this->select)($this->selectSql() . " WHERE o.fill_id = {$fillId} AND " . $scope->predicate()
            . ' ORDER BY o.traded_at DESC LIMIT 1 FORMAT JSONEachRow');
    }

    private function selectSql(): string
    {
        $timezone = ($this->quote)($this->timezone);
        return "SELECT toString(o.fill_id) AS fill_id, toString(o.trade_id) AS trade_id, toString(o.order_id) AS order_id,
            toString(o.user_id) AS user_id, o.home_site_id, o.agent_id, o.user_type, o.symbol, toString(o.position_id) AS position_id,
            o.side, o.position_side, o.price, o.quantity, o.notional AS trade_value,
            toString(abs(toDecimal128OrZero(o.fee, 18))) AS handling_fee, o.realized_pnl, o.role_type,
            formatDateTime(toTimeZone(o.traded_at, {$timezone}), '%F %T') AS trade_time
            FROM {$this->database}.history_trade_fills AS o";
    }

    private function where(TradeFillListQuery $q, TradeFillOwnerScope $scope, array $keywordUserIds): string
    {
        $where = [$scope->predicate()];
        if ($q->keyword() !== '') {
            $ids = array_values(array_unique(array_filter(array_map('intval', $keywordUserIds), static fn (int $id): bool => $id > 0)));
            $keyword = $ids === [] ? [] : ['o.user_id IN (' . implode(', ', $ids) . ')'];
            if (ctype_digit($q->keyword())) foreach (['user_id','fill_id','trade_id','order_id','position_id'] as $column) $keyword[] = "o.{$column} = " . (int) $q->keyword();
            $where[] = $keyword === [] ? '0' : '(' . implode(' OR ', $keyword) . ')';
        }
        if ($q->positionId() !== null) $where[] = 'o.position_id = ' . $q->positionId();
        if ($q->placeType() !== '') $where[] = 'upperUTF8(o.place_type) = ' . ($this->quote)($q->placeType());
        if ($q->userType() !== null) $where[] = 'o.user_type = ' . $q->userType();
        if ($q->symbol() !== '') $where[] = 'positionCaseInsensitive(o.symbol, ' . ($this->quote)($q->symbol()) . ') > 0';
        if ($q->marginMode() !== null) $where[] = "o.position_id IN (SELECT position_id FROM {$this->database}.current_position_query WHERE margin_mode = {$q->marginMode()})";
        if ($q->side() !== '') $where[] = 'lowerUTF8(o.side) = ' . ($this->quote)($q->side());
        if ($q->roleType() !== '') $where[] = 'lowerUTF8(o.role_type) = ' . ($this->quote)($q->roleType());
        [$start, $end, $exclusive] = $this->timeRange === null ? [$q->tradeTimeStart(), $q->tradeTimeEnd(), false]
            : ($this->timeRange)($q->tradeTimeStart(), $q->tradeTimeEnd());
        if ($start !== null && $start !== '') $where[] = 'o.traded_at >= toDateTime64(' . ($this->quote)($start) . ", 3, 'UTC')";
        if ($end !== null && $end !== '') $where[] = 'o.traded_at ' . ($exclusive ? '<' : '<=') . ' toDateTime64(' . ($this->quote)($end) . ", 3, 'UTC')";
        return implode(' AND ', $where);
    }
}
