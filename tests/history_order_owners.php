<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use SixMm\Shared\HistoryOrders\{HistoryOrderListQuery, HistoryOrderOwnerQueryService, HistoryOrderUserContextService};
use Illuminate\Database\Capsule\Manager as Capsule;

$checks=0;
function historyOwnerCheck(bool $condition, string $message): void {
    global $checks; ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
$sql=[];
$query=new HistoryOrderOwnerQueryService(function (string $statement) use (&$sql): array {
    $sql[]=$statement;
    return str_contains($statement,'count() AS total') ? [['total'=>1]] : [['order_id'=>'7']];
}, 'history', static fn (string $value): string => "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $value)."'");
$result=$query->search(new HistoryOrderListQuery(page:2,pageSize:20), ['o.home_site_id = 1001','o.agent_id = 10']);
historyOwnerCheck($result['count']===1 && count($result['lists'])===1,'Response envelope');
foreach ($sql as $statement) {
    historyOwnerCheck(str_contains($statement,'o.home_site_id = 1001') && str_contains($statement,'o.agent_id = 10'),'Count/page owner scope differs');
    historyOwnerCheck(!str_contains($statement,'o.user_id IN'),'Unfiltered request must not preload users');
    historyOwnerCheck(str_contains($statement,"'FILLED', 'CANCELED', 'REJECTED', 'PARTIAL_FILLED_CANCELED'"),'Only terminal orders');
}
historyOwnerCheck(str_contains($sql[1],'OFFSET 20') && str_contains($sql[1],'o.home_site_id'),'Page selects historical owner');
$sql=[];
$query->search(new HistoryOrderListQuery(keyword:'external',symbol:"BTC'",marginMode:1,side:'sell',leverage:20,reduceOnly:false,
    makerOnly:true,orderStatuses:[4],orderBy:'price',orderDirection:'asc',createdAtStart:'2026-11-01 00:00:00',createdAtEnd:'2026-11-17 23:59:59'),
    ['o.home_site_id = 1001','o.agent_id = 0'], [7]);
foreach ($sql as $statement) foreach (["o.final_status IN ('CANCELED')",'o.user_id IN (7)',"BTC\\'",'o.margin_mode = 1',
    'o.leverage = 20','o.reduce_only = 0','o.maker_only = 1','o.created_at >=','o.created_at <='] as $predicate) {
    historyOwnerCheck(str_contains($statement,$predicate),'Missing filter '.$predicate);
}
historyOwnerCheck(str_contains($sql[1],'o.price ASC'),'Sort whitelist');
try { $query->search(new HistoryOrderListQuery(), []); throw new RuntimeException('Missing owner accepted'); }
catch (InvalidArgumentException) { ++$checks; }

$capsule=new Capsule(); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']); $capsule->setAsGlobal();
$db=$capsule->getConnection();
$db->statement('CREATE TABLE users(user_id integer,public_user_id integer,home_site_id integer,agent_id integer,username text,nick_name text,user_type integer,deleted_at datetime)');
$db->statement('CREATE TABLE agent_user_bindings(agent_id integer,platform_user_id integer,agent_user_id text,bind_status text)');
$db->table('users')->insert(['user_id'=>7,'public_user_id'=>9007,'home_site_id'=>9999,'agent_id'=>99,'username'=>'moved','nick_name'=>'old','user_type'=>2,'deleted_at'=>'2026-11-01']);
$db->table('agent_user_bindings')->insert([
    ['agent_id'=>10,'platform_user_id'=>7,'agent_user_id'=>'old-owner','bind_status'=>'BOUND'],
    ['agent_id'=>99,'platform_user_id'=>7,'agent_user_id'=>'new-owner','bind_status'=>'BOUND'],
]);
$rows=(new HistoryOrderUserContextService($db))->hydrateHistoricalRows([
    ['order_id'=>'1','user_id'=>7,'home_site_id'=>'1001','agent_id'=>'10','user_type'=>1],
    ['order_id'=>'2','user_id'=>123,'home_site_id'=>'0','agent_id'=>'0','user_type'=>1],
]);
historyOwnerCheck(count($rows)===2,'Missing/deleted current user must not delete authorized history');
historyOwnerCheck($rows[0]['agent_id']==='10' && $rows[0]['home_site_id']==='1001','Current identity overwrote CK owner');
historyOwnerCheck($rows[0]['user_id']===9007 && $rows[0]['user_type']===1,'UID/type changed');
historyOwnerCheck($rows[0]['agent_user_id']==='old-owner','External ID must match CK agent');
historyOwnerCheck($rows[1]['user_id']===null && $rows[1]['platform_user_id']===123,'Missing identity must not masquerade as a public UID');
echo "Historical-order owners: {$checks} checks passed\n";
