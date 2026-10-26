<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Connection;
use Throwable;
use UnexpectedValueException;

class GridOrderRepository
{
    private const TABLE='grid_contract.grid_orders';
    private const LIST_COLUMNS='order_id,home_site_id,entry_site_id,user_id,symbol,settlement_asset,stake_amount_units,locked_gross_payout_units,locked_net_profit_units,locked_multiplier_cent,cell_id,time_start_ms,time_end_ms,price_low,price_high,status,order_version,result_code,settled_at_ms,created_at,updated_at';
    private const DETAIL_COLUMNS=self::LIST_COLUMNS.',client_order_id,public_quote_id,quote_id,locked_layout_version,locked_config_version,locked_model_version,bet_close_time_ms,locked_hit_probability_units,hit_time_ms,hit_mark_price,payout_discarded_remainder,result_message';

    public function __construct(private DatabaseManager $database,private GridOrderPresenter $presenter) {}

    public function listOrders(GridScope $scope,array $filters): array
    {
        [$where,$bindings]=$this->predicate($scope,$filters);
        return $this->withinCurrentScope($scope, fn (): array => $this->withReadSnapshot(function (Connection $connection) use ($where,$bindings,$filters): array {
            $stamp=$connection->selectOne("SELECT floor(extract(epoch FROM transaction_timestamp())*1000)::text AS snapshot_ms")->snapshot_ms;
            $count=(string)$connection->selectOne('SELECT count(*) AS total FROM '.self::TABLE.' WHERE '.$where,$bindings)->total;
            if (!preg_match('/^(0|[1-9][0-9]*)$/D',$count) || strlen($count)>16 || (strlen($count)===16 && strcmp($count,'9007199254740991')>0)) { throw new UnexpectedValueException('Grid total exceeds JSON integer range'); }
            [$displayColumns,$hasFunding]=$this->displayProjection($connection);
            $records=$connection->select('SELECT '.self::LIST_COLUMNS.$displayColumns.' FROM '.self::TABLE.' WHERE '.$where.' ORDER BY created_at DESC,order_id DESC LIMIT ? OFFSET ?',array_merge($bindings,[$filters['page_size'],($filters['page_no']-1)*$filters['page_size']]));
            $funding=$this->fundingForPage($connection,$records,$hasFunding);
            $lists=[];
            foreach ($records as $record) {
                $row=(array)$record;
                $row['funding_read_available']=$hasFunding;
                $lists[]=$this->presenter->row($row+($funding[(string)$row['order_id']]??[]));
            }
            return ['lists'=>$lists,
                'page_no'=>$filters['page_no'],'page_size'=>$filters['page_size'],'count'=>(int)$count,
                'extend'=>['query_snapshot_at_ms'=>(string)$stamp]];
        }));
    }

    /** List-authorized location; deliberately selects no detail-only fields. */
    public function locateOrder(GridScope $scope,string $orderId): array
    {
        [$where,$bindings]=$this->predicate($scope,['order_id'=>$orderId]);
        return $this->withinCurrentScope($scope, fn (): array => $this->withReadSnapshot(function (Connection $connection) use ($where,$bindings): array {
            $record=$connection->selectOne('SELECT order_id,status,created_at FROM '.self::TABLE.' WHERE '.$where.' LIMIT 1',$bindings);
            if ($record===null) { GridResponder::reject('ORDER_NOT_FOUND',404); }
            $status=(string)$record->status;
            if (!in_array($status,['1','2','3','4','5','6','7','8'],true) || !is_string($record->created_at) || $record->created_at==='') {
                throw new UnexpectedValueException('Invalid Grid order location');
            }
            $ms=(new \DateTimeImmutable($record->created_at))->format('Uv');
            if (!ctype_digit($ms) || (int)$ms>253402300799999) { throw new UnexpectedValueException('Invalid Grid creation time'); }
            return ['segment'=>'grid','order_id'=>(string)$record->order_id,'created_at_ms'=>$ms,'view'=>(int)$status<=4?'current':'history'];
        }));
    }

    public function getOrder(GridScope $scope,string $orderId): array
    {
        [$where,$bindings]=$this->predicate($scope,['order_id'=>$orderId]);
        return $this->withinCurrentScope($scope, fn (): array => $this->withReadSnapshot(function (Connection $connection) use ($where,$bindings): array {
            [$displayColumns,$hasFunding]=$this->displayProjection($connection);
            $record=$connection->selectOne('SELECT '.self::DETAIL_COLUMNS.$displayColumns.' FROM '.self::TABLE.' WHERE '.$where.' LIMIT 1',$bindings);
            if ($record===null) { GridResponder::reject('ORDER_NOT_FOUND',404); }
            $funding=$this->fundingForPage($connection,[$record],$hasFunding);
            $row=(array)$record;
            $row['funding_read_available']=$hasFunding;
            return ['order'=>$this->presenter->detail($row+($funding[(string)$row['order_id']]??[]))];
        }));
    }

    /** Discover optional Grid v3 columns inside the same read snapshot as the order. */
    private function displayProjection(Connection $connection): array
    {
        $schema=$connection->selectOne("SELECT
            EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='grid_contract.grid_orders'::regclass AND attname='order_no' AND NOT attisdropped)::int AS has_order_no,
            EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='grid_contract.grid_orders'::regclass AND attname='settlement_mark_price' AND NOT attisdropped)::int AS has_settlement_mark_price,
            EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='grid_contract.grid_orders'::regclass AND attname='settlement_price_time_ms' AND NOT attisdropped)::int AS has_settlement_price_time_ms,
            COALESCE(has_table_privilege(to_regclass('grid_contract.grid_funding_actions'),'SELECT'),false)::int AS can_read_funding");
        $columns=',' . ((int)$schema->has_order_no===1?'order_no':'NULL::text AS order_no');
        $columns.=',' . ((int)$schema->has_settlement_mark_price===1?'settlement_mark_price::text AS settlement_mark_price':'NULL::text AS settlement_mark_price');
        $columns.=',' . ((int)$schema->has_settlement_price_time_ms===1?'settlement_price_time_ms::text AS settlement_price_time_ms':'NULL::text AS settlement_price_time_ms');
        return [$columns,(int)$schema->can_read_funding===1];
    }

    /** The input records have already passed the order scope, sorting and page limit. */
    private function fundingForPage(Connection $connection,array $records,bool $hasFunding): array
    {
        if (!$hasFunding || $records===[]) { return []; }
        $ids=array_map(static fn ($record): string=>(string)$record->order_id,$records);
        $values=implode(',',array_fill(0,count($ids),'(?::bigint)'));
        $rows=$connection->select("SELECT page.order_id::text AS order_id,
            COALESCE(NULLIF(d.result_payload->>'ledgerId',''),NULLIF(d.result_payload->>'ledger_id','')) AS debit_ledger_id,
            CASE c.request_payload->>'business_type' WHEN 'SECONDS_BET_PAYOUT' THEN 'PAYOUT' WHEN 'SECONDS_BET_REFUND' THEN 'REFUND' END AS settlement_funding_type,
            COALESCE(NULLIF(c.result_payload->>'ledgerId',''),NULLIF(c.result_payload->>'ledger_id','')) AS settlement_ledger_id
            FROM (VALUES ".$values.") AS page(order_id)
            LEFT JOIN grid_contract.grid_funding_actions AS d ON d.order_id=page.order_id AND d.action_type=1 AND d.action_status=3
            LEFT JOIN grid_contract.grid_funding_actions AS c ON c.order_id=page.order_id AND c.action_type=2 AND c.action_status=3
                AND c.request_payload->>'business_type' IN ('SECONDS_BET_PAYOUT','SECONDS_BET_REFUND')",$ids);
        $funding=[];
        foreach ($rows as $row) { $funding[(string)$row->order_id]=(array)$row; }
        return $funding;
    }

    private function predicate(GridScope $scope,array $filters): array
    {
        if (!$scope->isCurrent()) { GridResponder::reject('SCOPE_UNAVAILABLE',503); }
        if (isset($filters['home_site_id']) && !$scope->allowsSite($filters['home_site_id'])) { GridResponder::reject('FORBIDDEN',403); }
        $where=[];$bindings=[];
        if (!$scope->isGlobal()) {
            $sites=$scope->homeSiteIds();$where[]='home_site_id IN ('.implode(',',array_fill(0,count($sites),'?')).')';array_push($bindings,...$sites);
        }
        $agents=$scope->orderAgentIds();
        if ($agents!==null) {
            $where[]=$agents===[]?'FALSE':'agent_id IN ('.implode(',',array_fill(0,count($agents),'?')).')';
            array_push($bindings,...$agents);
        }
        if (isset($filters['created_from_ms'])) {
            // Integer arithmetic preserves every millisecond, including the year-9999 boundary.
            // PostgreSQL's interval multiplication would otherwise coerce the epoch amount to float8.
            $where[]='created_at >= CAST(? AS timestamptz)';
            $where[]='created_at < CAST(? AS timestamptz)';
            $bindings[]=$this->utcTimestamp($filters['created_from_ms']);$bindings[]=$this->utcTimestamp($filters['created_to_ms']);
        }
        foreach (['order_id','order_no','user_id','client_order_id','home_site_id','symbol','status'] as $key) {
            if (isset($filters[$key])) { $where[]=$key.' = ?';$bindings[]=$filters[$key]; }
        }
        // This set is resolved from public UID by the server, never accepted from clients.
        if (isset($filters['user_filter'])) {
            $ids=$filters['user_filter']['user_ids'];
            $where[]=$ids===[]?'FALSE':'user_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
            array_push($bindings,...$ids);
        }
        if (isset($filters['statuses'])) {
            $where[]='status IN ('.implode(',',array_fill(0,count($filters['statuses']),'?')).')';array_push($bindings,...$filters['statuses']);
        }
        return [$where===[]?'TRUE':implode(' AND ',$where),$bindings];
    }

    private function utcTimestamp(string $milliseconds): string
    {
        $value=(int)$milliseconds;
        $time=new \DateTimeImmutable('@'.intdiv($value,1000));
        return $time->format('Y-m-d H:i:s').sprintf('.%03d+00',$value%1000);
    }

    private function withinCurrentScope(GridScope $scope, \Closure $query): array
    {
        try { $result=$query(); }
        catch (Throwable $failure) {
            if (!$scope->isCurrent()) { GridResponder::reject('SCOPE_UNAVAILABLE',503); }
            throw $failure;
        }
        if (!$scope->isCurrent()) { GridResponder::reject('SCOPE_UNAVAILABLE',503); }
        return $result;
    }

    private function withReadSnapshot(\Closure $query): array
    {
        $connection=$this->database->connection('grid_orders');
        try {
            $connection->beginTransaction();
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $connection->unprepared("SET LOCAL statement_timeout='2s'; SET LOCAL lock_timeout='500ms'; SET LOCAL idle_in_transaction_session_timeout='3s'");
            $result=$query($connection);
            $connection->commit();
            return $result;
        } catch (Throwable $failure) {
            try { if ($connection->transactionLevel()>0) { $connection->rollBack(); } } catch (Throwable $rollbackFailure) { /* Closing the failed session is the final rollback boundary. */ }
            $this->database->purge('grid_orders');
            throw $failure;
        }
    }
}
