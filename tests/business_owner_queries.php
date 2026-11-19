<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use SixMm\Shared\DataScope\{AllUsersScope, BusinessOwnerScope};
use SixMm\Shared\TradeFills\TradeFillOwnerScope;
use SixMm\Shared\CurrentOrders\OwnerCurrentOrderPager;
use SixMm\Shared\ConditionOrders\{ConditionOrderListQueryService, ConditionOrderListQuery};
use SixMm\Shared\FundingAccounts\{FundingAccountListQueryService, FundingAccountListQuery};
use SixMm\Shared\FundingChangeLogs\{FundingChangeLogListQueryService, FundingChangeLogListQuery};
use SixMm\Shared\FuturesReports\RollingTradeVolumeQuery;
use SixMm\Shared\LiquidationWarnings\LiquidationWarningListService;
use SixMm\Shared\CurrentPositions\CurrentPositionListQuery;

$checks = 0;
function businessCheck(bool $ok, string $message): void {
    global $checks; ++$checks;
    if (!$ok) throw new RuntimeException($message);
}
$capsule = new Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement('CREATE TABLE owners (id integer, site text, agent integer)');
foreach ([[1,'0',0], [2,'1001',0], [3,'1001',7], [4,'1002',7], [5,'0',7], [6,null,0], [7,'invalid',0], [8,'01',0], [9,'1001',8]] as [$id,$site,$agent]) {
    $db->table('owners')->insert(compact('id','site','agent'));
}
$exact = new TradeFillOwnerScope([[1001,7]]);
foreach ([[TradeFillOwnerScope::all(),[1,2,3,4,9]], [TradeFillOwnerScope::category('white_label'),[2]],
    [TradeFillOwnerScope::category('merchant'),[3,4,9]], [new TradeFillOwnerScope([[0,0]]),[1]], [$exact,[3]], [new TradeFillOwnerScope([]),[]]] as [$owner,$ids]) {
    $q = $db->table('owners');
    (new BusinessOwnerScope($owner, 'site', true))->apply($q, 'agent');
    businessCheck($q->orderBy('id')->pluck('id')->all() === $ids, 'Text owner category mismatch');
}
businessCheck(!TradeFillOwnerScope::all()->matches('',0) && !TradeFillOwnerScope::all()->matches('0',7), 'Unknown RPC owner accepted');

$db->statement('CREATE TABLE users (user_id integer,public_user_id text,username text,nick_name text,user_type integer,agent_id integer,deleted_at text)');
$db->statement('CREATE TABLE agent_user_bindings (platform_user_id integer,agent_id integer,agent_user_id text,bind_status text)');
$db->table('users')->insert(['user_id'=>1,'public_user_id'=>'9001','username'=>'moved','user_type'=>2,'agent_id'=>99]);
$db->statement('CREATE TABLE funding_accounts (account_id integer,user_id integer,home_site_id text,agent_id integer,status text)');
$db->statement('CREATE TABLE funding_assets (asset_id integer,asset_code text,internal_scale integer,status text)');
$db->statement('CREATE TABLE funding_balances (balance_id integer,account_id integer,asset_id integer,available_amount integer,held_amount integer,updated_at text)');
$db->statement('CREATE TABLE funding_ledger_entries (ledger_id integer,user_id integer,home_site_id text,account_id integer,asset_id integer,entry_type text,business_type text,business_scope text,business_id integer,business_operation_id integer,related_operation_id integer,created_at text,available_delta integer,held_delta integer,available_before integer,available_after integer,held_before integer,held_after integer)');
$db->table('funding_assets')->insert(['asset_id'=>1,'asset_code'=>'USDT','internal_scale'=>2,'status'=>'ENABLED']);
foreach ([[1,'1001',7], [2,'1002',7], [3,'1001',0]] as [$id,$site,$agent]) {
    $db->table('funding_accounts')->insert(['account_id'=>$id,'user_id'=>1,'home_site_id'=>$site,'agent_id'=>$agent,'status'=>'ACTIVE']);
    $db->table('funding_balances')->insert(['balance_id'=>$id,'account_id'=>$id,'asset_id'=>1,'available_amount'=>123,'held_amount'=>2,'updated_at'=>'2026-11-19']);
    $db->table('funding_ledger_entries')->insert(['ledger_id'=>$id,'user_id'=>1,'home_site_id'=>$site,'account_id'=>$id,'asset_id'=>1,
        'business_type'=>'DEPOSIT','entry_type'=>'CREDIT','business_id'=>$id,'available_delta'=>123,'held_delta'=>0,
        'available_before'=>0,'available_after'=>123,'held_before'=>0,'held_after'=>0,'created_at'=>'2026-11-19']);
}
$all = new AllUsersScope();
$db->enableQueryLog();
$accounts = (new FundingAccountListQueryService($db,$db))->search(new FundingAccountListQuery(),new BusinessOwnerScope($exact,'accounts.home_site_id',true),$all);
businessCheck(array_column($accounts->items(),'id') === [1] && $accounts->items()[0]['available_balance'] === '1.23', 'Funding account owner or decimals changed');
businessCheck(!str_contains($db->getQueryLog()[0]['query'],'users'), 'Funding account enumerated users');
$flows = (new FundingChangeLogListQueryService($db,$db))->search(new FundingChangeLogListQuery(),new BusinessOwnerScope($exact,'ledger.home_site_id',true),$all);
businessCheck(array_column($flows->items(),'id') === ['1'], 'Funding ledger owner mismatch');
$db->table('funding_ledger_entries')->where('ledger_id',1)->update(['home_site_id'=>'9999']);
businessCheck((new FundingChangeLogListQueryService($db,$db))->search(new FundingChangeLogListQuery(),new BusinessOwnerScope($exact,'ledger.home_site_id',true),$all)->total() === 0, 'Ledger/account owner mismatch accepted');

$db->statement('CREATE TABLE condition_orders (condition_id text,user_id integer,home_site_id integer,agent_id integer,user_type integer,trigger_status integer,trigger_type text,close_position integer,generated_order_id text,order_id text,client_order_id text,symbol text,side text,reduce_only integer,created_at text,deleted_at text)');
$db->statement('CREATE TABLE orders (order_id text,filled_quantity text)');
foreach ([[1,1001,7], [2,1002,7], [3,1001,0], [4,1001,7]] as [$id,$site,$agent]) {
    $db->table('condition_orders')->insert(['condition_id'=>(string)$id,'user_id'=>$id===4?404:1,'home_site_id'=>$site,'agent_id'=>$agent,
        'user_type'=>1,'trigger_status'=>0,'trigger_type'=>'stop_market','close_position'=>0,'symbol'=>'BTCUSDT','created_at'=>'2026-11-19']);
}
$db->flushQueryLog();
$conditions = (new ConditionOrderListQueryService($db))->searchByOwner(new ConditionOrderListQuery(
    \SixMm\Shared\ConditionOrders\ConditionOrderKind::CONDITION, \SixMm\Shared\ConditionOrders\ConditionOrderLifecycle::CURRENT, pageSize:1),$exact);
businessCheck($conditions->total()===2 && count($conditions->items())===1,'Condition owner must survive migrated/missing users');
businessCheck(!str_contains($db->getQueryLog()[0]['query'],'users'), 'Condition count unnecessarily joined users');

$rows = [
    ['order_id'=>'1','user_id'=>1,'home_site_id'=>'1002','agent_id'=>7],
    ['order_id'=>'2','user_id'=>1,'home_site_id'=>'1001','agent_id'=>7],
    ['order_id'=>'3','user_id'=>1,'home_site_id'=>'0','agent_id'=>0],
    ['order_id'=>'4','user_id'=>1,'home_site_id'=>'1001','agent_id'=>7],
    ['order_id'=>'5','user_id'=>404,'home_site_id'=>'1001','agent_id'=>7],
];
$calls = [];
$fetch = static function (array $params, ?array $ids) use ($rows,&$calls): array {
    businessCheck($ids===null,'Unfiltered RPC loaded a user list');
    $calls[]=$params;
    $offset=(int)($params['cursor']??0);
    $page=array_slice($rows,$offset,$params['page_size']);
    return ['lists'=>$page,'has_more'=>$offset+count($page)<count($rows),'next_cursor'=>(string)($offset+count($page)),'scope_applied'=>true];
};
$pager = new OwnerCurrentOrderPager($db,$fetch);
$page = $pager->search(['page_size'=>2],$exact);
businessCheck(array_column($page['lists'],'order_id')===['2','4'] && $page['has_more'],'Owner RPC page skipped or leaked rows');
$next = $pager->search(['page_size'=>2,'cursor'=>$page['next_cursor']],$exact);
businessCheck(array_column($next['lists'],'order_id')===['5'] && !$next['has_more'],'Owner cursor lost buffered rows');
businessCheck($calls[0]['home_site_id']==='1001' && $calls[0]['agent_id']===7,'Exact owner not sent to TK');
try { $pager->search(['page_size'=>2,'cursor'=>$page['next_cursor']],new TradeFillOwnerScope([[1002,7]])); throw new LogicException('Scope-changing cursor accepted'); }
catch (InvalidArgumentException) { ++$checks; }
$platform = $pager->search(['page_size'=>2],new TradeFillOwnerScope([[0,0]]));
businessCheck(array_column($platform['lists'],'order_id')===['3'],'Platform RPC snapshot filter mismatch');
try { (new OwnerCurrentOrderPager($db,static fn()=>['lists'=>[]]))->search(['page_size'=>2],$exact); throw new LogicException('Unapplied native scope accepted'); }
catch (RuntimeException) { ++$checks; }

$db->statement('CREATE TABLE user_daily_volume (user_id integer,home_site_id integer,agent_id integer,trade_date text,volume numeric)');
foreach ([[1001,7,'2026-10-21',2], [1001,7,'2026-11-19',3], [1002,7,'2026-11-19',100], [1001,8,'2026-11-19',100]] as [$site,$agent,$date,$volume]) {
    $db->table('user_daily_volume')->insert(['user_id'=>1,'home_site_id'=>$site,'agent_id'=>$agent,'trade_date'=>$date,'volume'=>$volume]);
}
$db->flushQueryLog();
$rolling=(new RollingTradeVolumeQuery($db))->search(['2026-11-18','2026-11-19'],$exact);
businessCheck($rolling['2026-11-19']===['trade_volume_30d'=>'5','trade_volume_29d'=>'3'],'Rolling window bounds or owner incorrect');
businessCheck(count($db->getQueryLog())===1,'Rolling volume still issues per-date queries');

$rpcCalls=[]; $sql=[];
$warning=new LiquidationWarningListService($db,static function ($threshold,$limit,$last,$pair,$cursor) use (&$rpcCalls): array {
    $rpcCalls[]=[$last,$pair,$cursor];
    return ['scope_applied'=>true,'has_more'=>$cursor==='','next_cursor'=>'next', 'positions'=>[
        ['user_id'=>1,'home_site_id'=>'1001','agent_id'=>7,'symbol'=>'BTCUSDT','margin_mode'=>1,'side'=>1,'quantity'=>'2'],
        ['user_id'=>1,'home_site_id'=>'1002','agent_id'=>7,'symbol'=>'BTCUSDT','margin_mode'=>1,'side'=>1],
    ]];
}, static function ($statement) use (&$sql): array {
    $sql[]=$statement;
    return str_contains($statement,'count()') ? [['total'=>1]] : [['position_id'=>'1','user_id'=>1,'home_site_id'=>'1001','agent_id'=>7,'symbol'=>'BTCUSDT','margin_mode'=>1,'position_side'=>'long']];
});
$page=$warning->search(new CurrentPositionListQuery(),$all,0.8,$exact);
businessCheck($page->total()===1 && $page->items()[0]['quantity']==='2','Owner risk page/hydration failed');
businessCheck($rpcCalls===[[0,[1001,7],''],[0,[1001,7],'next']],'Risk query did not use scoped row cursor');
foreach ($sql as $statement) businessCheck(str_contains($statement,'o.home_site_id = 1001 AND o.agent_id = 7') && !str_contains($statement,"(1002, 7, 1,"),'Risk CK query leaked another owner');

echo "Business owner queries: {$checks} checks passed\n";
