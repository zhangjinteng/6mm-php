<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\PredictionReports\PredictionUserPnlPublisher;
use SixMm\Shared\PredictionReports\PredictionUserPnlSchema;
use SixMm\Shared\PredictionReports\PredictionUserPnlStore;

require dirname(__DIR__) . '/vendor/autoload.php';

function predictionCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id')->nullable();
    $t->string('agent_code'); $t->string('agent_name'); $t->string('user_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable(); $t->bigInteger('agent_id'); $t->integer('user_type');
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
PredictionUserPnlSchema::create($db);
$db->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'home_site_id' => 100, 'agent_code' => 'A1', 'agent_name' => 'Merchant One', 'user_name' => 'one'],
    ['id' => 2, 'pid' => 0, 'home_site_id' => 200, 'agent_code' => 'A2', 'agent_name' => 'Merchant Two', 'user_name' => 'two'],
    ['id' => 4, 'pid' => 1, 'home_site_id' => 100, 'agent_code' => 'A4', 'agent_name' => 'Sub agent', 'user_name' => 'sub'],
]);
$db->table('users')->insert([
    ['user_id' => 11, 'home_site_id' => 100, 'agent_id' => 1, 'user_type' => 2],
    ['user_id' => 12, 'home_site_id' => 100, 'agent_id' => 4, 'user_type' => 2],
    ['user_id' => 13, 'home_site_id' => 200, 'agent_id' => 2, 'user_type' => 2],
    ['user_id' => 14, 'home_site_id' => 100, 'agent_id' => 0, 'user_type' => 2],
    ['user_id' => 15, 'home_site_id' => 100, 'agent_id' => 1, 'user_type' => 3],
]);
$db->table('wl_sites')->insert([['site_id' => 100, 'tenant_id' => 7], ['site_id' => 200, 'tenant_id' => 8]]);
$db->table('wl_tenants')->insert([['tenant_id' => 7, 'tenant_name' => 'White label 7'], ['tenant_id' => 8, 'tenant_name' => 'White label 8']]);
$facts = [
    ['user_id' => 11, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'win', 'order_count' => 1, 'stake_amount' => '10', 'return_amount' => '18'],
    ['user_id' => 11, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'loss', 'order_count' => 1, 'stake_amount' => '5', 'return_amount' => '0'],
    ['user_id' => 11, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'refund', 'order_count' => 1, 'stake_amount' => '3', 'return_amount' => '3'],
    ['user_id' => 12, 'play_type' => 'high_low', 'symbol' => 'ETHUSDT', 'outcome' => 'loss', 'order_count' => 1, 'stake_amount' => '4', 'return_amount' => '0'],
    ['user_id' => 13, 'play_type' => 'up_down', 'symbol' => 'BTCUSDT', 'outcome' => 'win', 'order_count' => 1, 'stake_amount' => '2', 'return_amount' => '4'],
    ['user_id' => 14, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'refund', 'order_count' => 1, 'stake_amount' => '7', 'return_amount' => '7'],
    ['user_id' => 15, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'win', 'order_count' => 1, 'stake_amount' => '999', 'return_amount' => '999'],
];
$store = new PredictionUserPnlStore($db);
$publisher = new PredictionUserPnlPublisher($db, null, $store);
$publisher->publish('2026-10-13', $facts);
$merchant = $store->search(['start_date' => '2026-10-13', 'end_date' => '2026-10-13'], '1')['lists'][0];
predictionCheck($merchant['trading_user_count'] === 2 && $merchant['valid_trading_user_count'] === 2, 'Direct merchant includes user types 2 and 3, not child-merchant users');
predictionCheck($merchant['profitable_user_count'] === 1 && $merchant['losing_user_count'] === 0 && $merchant['breakeven_user_count'] === 1, 'Per-user net classification includes the robot breakeven user');
predictionCheck($merchant['stake_amount'] === '1017' && $merchant['return_amount'] === '1020', 'Merchant includes robot users but excludes direct and other merchant users');
predictionCheck($merchant['user_net_pnl'] === '3' && $merchant['platform_pnl'] === '-3', 'Net and platform PnL must be opposites');
predictionCheck($merchant['valid_stake_amount'] === '1014' && $merchant['refund_order_count'] === 1, 'Refunds count but do not increase valid stake');
$child = $store->search(['source_type' => 'merchant', 'source_id' => '4', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13'])['lists'][0];
predictionCheck($child['trading_user_count'] === 1 && $child['stake_amount'] === '4' && $child['user_net_pnl'] === '-4', 'Child merchant is attributed directly, never rolled into its parent');
$platform = $store->search(['source_type' => 'platform', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13'])['lists'][0];
predictionCheck($platform['trading_user_count'] === 5 && $platform['stake_amount'] === '1030', 'Platform includes direct site users and robots exactly once');
$tenant = $store->search(['source_type' => 'tenant', 'source_id' => '7', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13'])['lists'][0];
predictionCheck($tenant['trading_user_count'] === 4 && $tenant['stake_amount'] === '1028', 'Tenant follows home-site origin across merchants and user types');
$grid = $store->search(['play_type' => 'grid', 'symbol' => 'BTCUSDT', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13'], '1')['lists'][0];
predictionCheck($grid['trading_user_count'] === 2 && $grid['order_count'] === 4, 'Play and symbol dimensions stay independent');
predictionCheck($store->search(['source_type' => 'merchant', 'source_keyword' => 'Merchant Two', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13'])['count'] === 1, 'Source search must filter platform results');
predictionCheck($store->search(['source_type' => 'platform', 'start_date' => '2026-10-13', 'end_date' => '2026-10-13'], '1')['count'] === 1, 'Merchant-supplied source type must be ignored');
predictionCheck($store->symbols('1') === ['BTCUSDT'], 'Merchant symbol options must not leak the child merchant symbol');
predictionCheck($publisher->publish('2026-10-13', $facts) > 0 && $store->search(['start_date' => '2026-10-13', 'end_date' => '2026-10-13'], '1')['count'] === 1, 'Publishing must be idempotent');
$db->table('users')->insert(['user_id' => 16, 'home_site_id' => 200, 'agent_id' => 1, 'user_type' => 2]);
$publisher->publish('2026-10-12', [
    ['user_id' => 16, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'win',
        'order_count' => 1, 'stake_amount' => '10', 'return_amount' => '20'],
]);
$incomplete = $store->search(['source_type' => 'platform', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12'])['lists'][0];
predictionCheck($incomplete['trading_user_count'] === 1 && $incomplete['stat_status'] === 1,
    'Different merchant and user sites still produce complete platform totals');
predictionCheck($store->search(['start_date' => '2026-10-12', 'end_date' => '2026-10-12'], '1')['lists'][0]['trading_user_count'] === 1,
    'Cross-site users enter the merchant selected by users.agent_id');
echo "prediction user pnl reports ok\n";
