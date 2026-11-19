<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Capsule\Manager;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;
use SixMm\Shared\UserPredictions\UserPredictionReportService;
use SixMm\Shared\Dashboard\SupportedDashboardQuery;

$checks = 0;
function predictionOwnerCheck(bool $ok, string $message): void {
    global $checks; ++$checks;
    if (!$ok) throw new RuntimeException($message);
}
final class OwnerPredictionRecordingConnection extends PostgresConnection
{
    public array $queries = [];
    public function select($query, $bindings = [], $useReadPdo = true) {
        $this->queries[] = [$query,$bindings];
        return str_contains($query, 'SELECT COUNT(*) AS aggregate') ? [(object)['aggregate'=>0]] : [];
    }
}
$recording = new OwnerPredictionRecordingConnection(null, '', '', ['driver'=>'pgsql']);
$service = new UserPredictionReportService($recording);
foreach ([TradeFillOwnerScope::all(), new TradeFillOwnerScope([[1001,0]]), new TradeFillOwnerScope([[1001,7]])] as $owner) {
    $recording->queries = [];
    $service->search([], 'all', 1, 20, null, null, 'last_prediction_at', 'desc', $owner, false);
    predictionOwnerCheck(count($recording->queries)===2, 'Owner report must count and paginate');
    foreach ($recording->queries as [$sql,$bindings]) {
        predictionOwnerCheck(!str_contains($sql,'ANY(') && !str_contains($sql,'FROM users'), 'Unfiltered prediction report preloads users');
        predictionOwnerCheck(str_contains($sql,'GROUP BY user_id, home_site_id, agent_id'), 'Prediction aggregation mixes owners');
        predictionOwnerCheck(str_contains($sql,'"orders"."home_site_id"') && str_contains($sql,'"orders"."agent_id"'), 'Missing native/grid owner filters');
    }
}
$recording->queries=[];
$service->search([1], 'high_low', 1, 20, null, null, 'pending_orders', 'asc', new TradeFillOwnerScope([[1001,7]]), true);
predictionOwnerCheck(str_contains($recording->queries[0][0],'orders.funding_user_id = ANY(?::bigint[])'), 'Prediction keyword uses external rather than funding identity');
predictionOwnerCheck(in_array('{1}',$recording->queries[0][1],true), 'Keyword identity not bound');

$capsule = new Manager(); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db = $capsule->getConnection();
foreach (['prediction','grid_contract'] as $schema) $db->statement("ATTACH DATABASE ':memory:' AS $schema");
$db->statement('CREATE TABLE wl_sites (site_id integer,tenant_id integer,status text)');
$db->statement('CREATE TABLE agents (id integer,home_site_id integer,deleted_at text)');
$db->table('wl_sites')->insert([
    ['site_id'=>1000,'tenant_id'=>101,'status'=>'disabled'], ['site_id'=>1001,'tenant_id'=>101,'status'=>'enabled'], ['site_id'=>1002,'tenant_id'=>101,'status'=>'enabled'],
]);
$db->table('agents')->insert(['id'=>7,'home_site_id'=>1001]);
$db->statement('CREATE TABLE prediction.prediction_order (order_id text,home_site_id text,agent_id text,funding_user_id integer,order_source text,settlement_asset text,game_type text,player_query_view text,fund_status text,business_status text,result text,stake_amount numeric,symbol text,created_at text)');
$db->statement('CREATE TABLE grid_contract.grid_orders (order_id integer,home_site_id text,agent_id integer,user_id integer,settlement_asset text,status integer,stake_amount_units integer,symbol text,created_at text)');
$db->statement('CREATE TABLE grid_contract.grid_order_state_logs (order_id integer,to_status integer)');
$db->statement('CREATE TABLE prediction.prediction_round (round_id text,routing_site_id text,agent_id text,state text,symbol text,bet_close_at text,created_at text)');
foreach ([[1,'1001',0], [2,'1002',0], [3,'1001',7], [4,'0',0], [5,'',0], [6,'0',7]] as [$id,$site,$agent]) {
    $db->table('prediction.prediction_order')->insert(['order_id'=>(string)$id,'home_site_id'=>$site,'agent_id'=>(string)$agent,'funding_user_id'=>404,
        'order_source'=>'PLAYER','settlement_asset'=>'USDT','game_type'=>'HIGH_LOW','player_query_view'=>'HOLDINGS','fund_status'=>'DEBITED',
        'business_status'=>'ACTIVE','result'=>'PENDING','stake_amount'=>1,'created_at'=>'2026-11-19','symbol'=>'BTCUSDT']);
    $db->table('grid_contract.grid_orders')->insert(['order_id'=>$id,'home_site_id'=>$site,'agent_id'=>$agent,'user_id'=>404,
        'settlement_asset'=>'USDT','status'=>2,'stake_amount_units'=>100000000,'created_at'=>'2026-11-19','symbol'=>'BTCUSDT']);
    $db->table('grid_contract.grid_order_state_logs')->insert(['order_id'=>$id,'to_status'=>2]);
    $db->table('prediction.prediction_round')->insert(['round_id'=>(string)$id,'routing_site_id'=>$site,'agent_id'=>(string)$agent,'state'=>'BETTING','symbol'=>'BTCUSDT','created_at'=>'2026-11-19']);
}
$method = new ReflectionMethod(SupportedDashboardQuery::class, 'prediction_runtime'); $method->setAccessible(true);
foreach ([['tenant','101',2], ['merchant','7',2], ['platform','0',8], ['tenant','999',0]] as [$type,$id,$count]) {
    $db->enableQueryLog(); $db->flushQueryLog();
    $runtime=$method->invoke(new SupportedDashboardQuery($db,$type,$id,null,$db));
    predictionOwnerCheck(count($runtime['orders'])===$count, 'Dashboard order owner mismatch');
    foreach ($db->getQueryLog() as $entry) predictionOwnerCheck(!str_contains($entry['query'],'"users"'), 'Dashboard still depends on a current user population');
    if ($type==='tenant' && $id==='101') predictionOwnerCheck(array_unique(array_column($runtime['orders'],'id'))===['1'], 'White-label selected multiple sites');
}
echo "Prediction owner queries: {$checks} checks passed\n";
