<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\AssetReports\SourceAssetReportSchema;
use SixMm\Shared\Dashboard\SupportedDashboardQuery;
use SixMm\Shared\FuturesReports\TradeReportSchema;
use SixMm\Shared\FuturesReports\TradeReportStore;

require dirname(__DIR__) . '/vendor/autoload.php';
function dashboardCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$capsule = new Capsule();
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('users', function (Blueprint $t): void {
    $t->integer('user_id'); $t->integer('home_site_id'); $t->integer('agent_id'); $t->integer('user_type');
    $t->timestamp('created_at'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('wl_sites', function (Blueprint $t): void { $t->integer('site_id'); $t->integer('tenant_id'); });
$db->table('wl_sites')->insert([['site_id'=>100,'tenant_id'=>7],['site_id'=>200,'tenant_id'=>8]]);
$db->table('users')->insert([
    ['user_id'=>1,'home_site_id'=>100,'agent_id'=>0,'user_type'=>1,'created_at'=>'2026-11-17 23:59:00'],
    ['user_id'=>2,'home_site_id'=>100,'agent_id'=>1,'user_type'=>1,'created_at'=>'2026-11-18 00:01:00'],
    ['user_id'=>3,'home_site_id'=>200,'agent_id'=>0,'user_type'=>1,'created_at'=>'2026-11-18 00:02:00'],
]);
TradeReportSchema::create($db);
\SixMm\Shared\FuturesReports\FeeReportSchema::create($db);
\SixMm\Shared\PredictionReports\PredictionUserPnlSchema::create($db);
\SixMm\Shared\FuturesReports\PositionReportSchema::create($db);
SourceAssetReportSchema::create($db);
$store = new TradeReportStore($db);
foreach (['2026-11-17','2026-11-18'] as $date) {
    $rows = [];
    foreach ([['platform','0','10.125'],['tenant','7','2.5'],['tenant','8','999'],['merchant','1','3'],['merchant','2','888']] as [$type,$id,$amount]) {
        foreach (['','BTCUSDT'] as $symbol) $rows[] = [
            'stat_date'=>$date,'source_type'=>$type,'source_id'=>$id,'source_code'=>$id,'source_name'=>$type.$id,'symbol'=>$symbol,
            'trading_user_count'=>1,'order_count'=>1,'order_amount'=>$amount,'filled_order_count'=>1,'fill_count'=>1,
            'trade_amount'=>$amount,'cancel_order_count'=>0,'cancel_amount'=>'0','status'=>1,
            'identities'=>['users'=>['same-user'=>true],'orders'=>[$date=>true],'executions'=>[$date=>$amount]],
        ];
    }
    $store->replace($date,$rows);
}
$snapshot = ['version'=>3,'user_types'=>'all','total'=>'123.1234567890123456','available'=>'120','occupied'=>'3.1234567890123456'];
$flows = ['version'=>3,'user_types'=>'all','deposit_in'=>'7.1234567890123456','withdraw_out'=>'2'];
foreach (['funding','contract'] as $account) $db->table(SourceAssetReportSchema::TABLE)->insert([
    'stat_date'=>'2026-11-18','source_type'=>'tenant','source_id'=>'7','source_name'=>'7','account_type'=>$account,'currency'=>'USDT',
    'asset_report_closing'=>json_encode($snapshot),'asset_report_flows'=>json_encode($flows),
]);
$now = CarbonImmutable::parse('2026-11-18T12:00:00Z');
$db->statement("ATTACH DATABASE ':memory:' AS prediction");
$db->statement("ATTACH DATABASE ':memory:' AS grid_contract");
$db->statement('CREATE TABLE prediction.prediction_order (order_id text, funding_user_id integer, game_type text, symbol text, stake_amount text, business_status text, fund_status text, result text, created_at text, player_query_view text, order_source text, settlement_asset text)');
$db->statement('CREATE TABLE prediction.prediction_round (round_id text, symbol text, state text, bet_close_at text, created_at text, routing_site_id integer, agent_id integer)');
$db->statement('CREATE TABLE grid_contract.grid_orders (order_id text, user_id integer, symbol text, stake_amount_units integer, status integer, created_at text, settlement_asset text)');
$db->statement('CREATE TABLE grid_contract.grid_order_state_logs (order_id text, to_status integer)');
foreach ([1,2,3] as $userId) $db->table('prediction.prediction_order')->insert(['order_id'=>'o'.$userId,'funding_user_id'=>$userId,
    'game_type'=>'HIGH_LOW','symbol'=>'BTCUSDT','stake_amount'=>'1.1234','business_status'=>'ACTIVE','fund_status'=>'DEBITED','result'=>'PENDING',
    'created_at'=>'2026-11-18 01:00:00','player_query_view'=>'HOLDINGS','order_source'=>'PLAYER','settlement_asset'=>'USDT']);
foreach ([[100,0,'r1'],[200,0,'r2'],[100,1,'r3']] as [$site,$agent,$round]) $db->table('prediction.prediction_round')->insert([
    'round_id'=>$round,'symbol'=>'BTCUSDT','state'=>'BETTING','bet_close_at'=>'2026-11-18 13:00:00','created_at'=>'2026-11-18 01:00:00','routing_site_id'=>$site,'agent_id'=>$agent]);
$db->table('grid_contract.grid_orders')->insert(['order_id'=>'g1','user_id'=>1,'symbol'=>'BTCUSDT','stake_amount_units'=>123456789,'status'=>2,'created_at'=>'2026-11-18 02:00:00','settlement_asset'=>'USDT']);
$db->table('grid_contract.grid_order_state_logs')->insert(['order_id'=>'g1','to_status'=>2]);
$failures = [];
$db->enableQueryLog();
$tenant = (new SupportedDashboardQuery($db,'tenant','7',function ($key) use (&$failures): void { $failures[] = $key; }))->fetch('7',$now);
dashboardCheck($tenant['blocks']['users']['data']['total'] === 1, 'Tenant counts only its direct users');
$trade = $tenant['blocks']['trade']['data'];
dashboardCheck($trade['totals']['trading_user_count'] === 1, 'Period users must be deduplicated');
dashboardCheck(bccomp($trade['totals']['trade_amount'],'5',16) === 0, 'Never double count dimension or foreign scope rows');
dashboardCheck(count($trade['coverage']['missing_dates']) === 5, 'Historical gaps remain explicit');
dashboardCheck(!isset($trade['totals']['identities']), 'Never send identity sets to browser');
$assets = $tenant['blocks']['assets']['data'];
dashboardCheck($assets['balances']['funding']['total'] === $snapshot['total'], 'Preserve exact monetary strings');
dashboardCheck($assets['as_of'] === null, 'Missing snapshot timestamp must not crash other data');
dashboardCheck($assets['daily'][0]['deposit'] === $flows['deposit_in'], 'Do not add contract transfers to external funding flow');
dashboardCheck($failures === [], 'Existing optional empty report tables are not errors');
dashboardCheck(!isset($tenant['blocks']['partners']), 'Partner totals must never be exposed to tenants');
$merchant = (new SupportedDashboardQuery($db,'merchant','1'))->fetch('7',$now);
dashboardCheck(bccomp($merchant['blocks']['trade']['data']['totals']['trade_amount'],'6',16) === 0, 'Merchant cannot read foreign merchant rows');
dashboardCheck($merchant['blocks']['merchant']['state'] === 'error' && $merchant['blocks']['trade']['state'] === 'ready', 'Isolate failed blocks and recover transaction');
$root = (new SupportedDashboardQuery($db,'platform','0'))->fetch('7',$now);
dashboardCheck(bccomp($root['blocks']['trade']['data']['totals']['trade_amount'],'20.25',16) === 0, 'Platform uses root publication once');
$older = (new SupportedDashboardQuery($db,'tenant','7'))->fetch('yesterday',$now);
dashboardCheck($older['blocks']['assets']['data']['balances']['funding'] === null, 'Never substitute a different date snapshot');
$runtime = (new SupportedDashboardQuery($db,'tenant','7',null,$db))->fetch('7',$now)['blocks']['prediction_runtime'];
dashboardCheck($runtime['state'] === 'ready', 'Read current orders without RPC writes');
dashboardCheck(array_column($runtime['data']['orders'],'id') === ['g1','o1'], 'Current orders use actual scoped funding user identity');
dashboardCheck(array_column($runtime['data']['rounds'],'id') === ['r1'], 'Tenant rounds exclude other sites and merchant-owned rounds');
dashboardCheck($runtime['data']['pending'][1]['amount'] === '1.2345678900000000', 'Grid amount scale is exact');
$merchantRuntime = (new SupportedDashboardQuery($db,'merchant','1',null,$db))->fetch('7',$now)['blocks']['prediction_runtime']['data'];
dashboardCheck(array_column($merchantRuntime['orders'],'id') === ['o2'] && array_column($merchantRuntime['rounds'],'id') === ['r3'], 'Merchant runtime cannot read tenant direct or foreign users');
foreach ([['tenant','0'],['merchant','1 OR 1=1'],['platform','7']] as [$type,$id]) {
    try { new SupportedDashboardQuery($db,$type,$id); throw new RuntimeException('Accepted invalid identity'); }
    catch (InvalidArgumentException $e) {}
}
try { (new SupportedDashboardQuery($db,'platform','0'))->fetch('all',$now); throw new RuntimeException('Accepted unbounded period'); }
catch (InvalidArgumentException $e) {}
foreach ($db->getQueryLog() as $entry) dashboardCheck(!preg_match('/^\s*(insert|update|delete|create|alter|drop)/i',$entry['query']), 'Dashboard must never write data');
echo "Supported dashboard scope, deduplication, decimals, coverage and read-only tests OK\n";
