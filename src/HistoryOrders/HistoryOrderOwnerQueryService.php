<?php
declare(strict_types=1);

namespace SixMm\Shared\HistoryOrders;

use Closure;
use InvalidArgumentException;

/** CK owns the result population; SQL owner predicates must come from trusted host identity code. */
final class HistoryOrderOwnerQueryService
{
    private Closure $select;
    private Closure $quote;
    private ?Closure $timeRange;

    public function __construct(callable $select, private string $database, callable $quote, private string $timezone='UTC', ?callable $timeRange=null)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $database)) throw new InvalidArgumentException('Invalid history database');
        $this->select=Closure::fromCallable($select);
        $this->quote=Closure::fromCallable($quote);
        $this->timeRange=$timeRange===null ? null : Closure::fromCallable($timeRange);
    }

    public function search(HistoryOrderListQuery $q, array $ownerWhere, array $keywordUserIds=[], array $fallbackTypeUserIds=[]): array
    {
        if ($ownerWhere===[]) throw new InvalidArgumentException('Historical order ownership is required');
        $where=implode(' AND ', $this->where($q,$ownerWhere,$keywordUserIds,$fallbackTypeUserIds));
        $from="FROM {$this->database}.history_order_query AS o WHERE {$where}";
        $total=(int) (($this->select)("SELECT count() AS total {$from} FORMAT JSONEachRow")[0]['total'] ?? 0);
        $result=['lists'=>[], 'count'=>$total, 'page'=>$q->page(), 'limit'=>$q->pageSize()];
        if ($total===0) return $result;
        $columns=['user_id'=>'o.user_id','order_id'=>'o.order_id','order_face_value'=>'toDecimal128OrZero(toString(o.price), 18) * toDecimal128OrZero(toString(o.quantity), 18)',
            'quantity'=>'o.quantity','price'=>'o.price','created_at'=>'o.created_at'];
        $order=$columns[$q->orderBy()]; $direction=strtoupper($q->orderDirection());
        $tail=$order==='o.created_at' ? 'o.order_id DESC' : 'o.created_at DESC, o.order_id DESC';
        $timezone=($this->quote)($this->timezone);
        $offset=($q->page()-1)*$q->pageSize();
        $sql="SELECT toString(o.user_id) AS user_id, toString(o.home_site_id) AS home_site_id,
            toString(o.agent_id) AS agent_id, o.user_type, o.symbol, toString(o.order_id) AS order_id,
            toString(o.position_id) AS position_id,
            formatDateTime(toTimeZone(o.created_at, {$timezone}), '%F %T') AS created_at,
            formatDateTime(toTimeZone(o.updated_at, {$timezone}), '%F %T') AS updated_at,
            o.order_type, o.side, o.price, o.quantity, o.filled_quantity, o.remaining_quantity,
            o.remaining_quantity AS remaining, o.avg_filled_price, o.avg_filled_price AS avg_price, o.final_status,
            multiIf(o.final_status = 'FILLED', 3, o.final_status = 'CANCELED', 4,
                o.final_status = 'REJECTED', 5, o.final_status = 'PARTIAL_FILLED_CANCELED', 6, 0) AS order_status,
            o.time_in_force, o.reduce_only, o.close_only, o.maker_only, o.leverage, o.margin_mode,
            o.client_order_id, 'order' AS place_type, 2 AS is_hide
            {$from} ORDER BY {$order} {$direction}, {$tail}
            LIMIT {$q->pageSize()} OFFSET {$offset} FORMAT JSONEachRow";
        $result['lists']=($this->select)($sql);
        return $result;
    }

    private function where(HistoryOrderListQuery $q, array $ownerWhere, array $keywordUserIds, array $fallbackTypeUserIds): array
    {
        $where=$ownerWhere;
        $statuses=[3=>'FILLED',4=>'CANCELED',5=>'REJECTED',6=>'PARTIAL_FILLED_CANCELED'];
        $selected=$q->orderStatuses()===[] ? array_values($statuses) : array_map(static fn (int $status): string => $statuses[$status], $q->orderStatuses());
        $where[]='o.final_status IN ('.implode(', ',array_map($this->quote,$selected)).')';
        if ($q->userType()!==null) {
            $type=['nullIf(o.user_type, 0) = '.$q->userType()];
            $ids=$this->ids($fallbackTypeUserIds);
            if ($ids!==[]) $type[]='(isNull(nullIf(o.user_type, 0)) AND o.user_id IN ('.implode(', ',$ids).'))';
            $where[]='('.implode(' OR ',$type).')';
        }
        if ($q->keyword()!=='') {
            $ids=$this->ids($keywordUserIds);
            $keyword=$ids===[] ? [] : ['o.user_id IN ('.implode(', ',$ids).')'];
            if (ctype_digit($q->keyword())) {
                $keyword[]='o.user_id = '.(int) $q->keyword();
                $keyword[]='o.order_id = '.(int) $q->keyword();
            }
            $where[]=$keyword===[] ? '0' : '('.implode(' OR ',$keyword).')';
        }
        if ($q->symbol()!=='') $where[]='positionCaseInsensitive(o.symbol, '.($this->quote)($q->symbol()).') > 0';
        if ($q->orderType()!=='') $where[]='lowerUTF8(o.order_type) = '.($this->quote)($q->orderType());
        if ($q->marginMode()!==null) $where[]='o.margin_mode = '.$q->marginMode();
        if ($q->side()!=='') $where[]='lowerUTF8(o.side) = '.($this->quote)($q->side());
        if ($q->leverage()!==null) $where[]='o.leverage = '.$q->leverage();
        if ($q->reduceOnly()!==null) $where[]='o.reduce_only = '.(int) $q->reduceOnly();
        if ($q->makerOnly()!==null) $where[]='o.maker_only = '.(int) $q->makerOnly();
        [$start,$end,$exclusive]=$this->timeRange===null ? [$q->createdAtStart(),$q->createdAtEnd(),false]
            : ($this->timeRange)($q->createdAtStart(),$q->createdAtEnd());
        if ($start!==null && $start!=='') $where[]='o.created_at >= toDateTime64('.($this->quote)($start).", 3, 'UTC')";
        if ($end!==null && $end!=='') $where[]='o.created_at '.($exclusive ? '<' : '<=').' toDateTime64('.($this->quote)($end).", 3, 'UTC')";
        return $where;
    }

    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval',$ids),static fn (int $id): bool => $id>0)));
    }
}
