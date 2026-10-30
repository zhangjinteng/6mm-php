<?php
declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\FuturesReports\TradeReportSchema;
use SixMm\Shared\PlatformReports\{TradeSummarySchema, TradeSummaryStore, TradeSummaryPublisher};

require dirname(__DIR__) . '/vendor/autoload.php';
$checks = 0;
function summaryCheck(bool $value, string $message): void { global $checks; ++$checks; if (!$value) throw new RuntimeException($message); }
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal(); $capsule->bootEloquent(); $db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    foreach (['id', 'pid', 'home_site_id'] as $key) $t->bigInteger($key);
    $t->string('agent_code'); $t->string('agent_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void { foreach (['user_id', 'agent_id', 'home_site_id', 'user_type'] as $key) $t->bigInteger($key); });
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$db->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'home_site_id' => 100, 'agent_code' => 'MER1', 'agent_name' => 'Merchant One'],
    ['id' => 2, 'pid' => 0, 'home_site_id' => 200, 'agent_code' => 'MER2', 'agent_name' => 'Merchant Two'],
    ['id' => 11, 'pid' => 1, 'home_site_id' => 0, 'agent_code' => 'SUB', 'agent_name' => 'Subaccount'],
]);
$db->table('users')->insert([
    ['user_id' => 809, 'agent_id' => 1, 'home_site_id' => 100, 'user_type' => 2],
    ['user_id' => 810, 'agent_id' => 1, 'home_site_id' => 100, 'user_type' => 1],
    ['user_id' => 811, 'agent_id' => 0, 'home_site_id' => 100, 'user_type' => 2],
    ['user_id' => 812, 'agent_id' => 1, 'home_site_id' => 0, 'user_type' => 2],
    ['user_id' => 813, 'agent_id' => 1, 'home_site_id' => 100, 'user_type' => 3],
    ['user_id' => 814, 'agent_id' => 1, 'home_site_id' => 200, 'user_type' => 2],
]);
$db->table('wl_sites')->insert([['site_id' => 100, 'tenant_id' => 900001], ['site_id' => 200, 'tenant_id' => 900002]]);
$db->table('wl_tenants')->insert([['tenant_id' => 900001, 'tenant_name' => 'White Label One'], ['tenant_id' => 900002, 'tenant_name' => 'White Label Two']]);
// Keep upstream decimal fixtures textual, just as PostgreSQL PDO returns NUMERIC.
$schema->create(TradeReportSchema::TABLE, static function (Blueprint $t): void {
    foreach (['stat_date', 'source_type', 'source_code', 'source_name', 'symbol', 'trade_amount', 'order_amount', 'cancel_amount', 'identities', 'updated_at'] as $field) $t->string($field);
    foreach (['source_id', 'trading_user_count', 'order_count', 'filled_order_count', 'fill_count', 'cancel_order_count', 'status'] as $field) $t->bigInteger($field);
});
TradeSummarySchema::create($db); TradeSummarySchema::create($db);
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
$contract = static function (string $date) use ($db): void {
    foreach ([['merchant', '1', 'Merchant One'], ['tenant', '900001', 'White Label One'], ['platform', '0', '6MM']] as [$type, $id, $name]) {
        $db->table(TradeReportSchema::TABLE)->insert(['stat_date' => $date, 'source_type' => $type, 'source_id' => $id,
            'source_code' => $id, 'source_name' => $name, 'symbol' => '', 'trading_user_count' => 1, 'order_count' => 1,
            'order_amount' => '100', 'filled_order_count' => 1, 'fill_count' => 3, 'trade_amount' => '0.1000000000000001',
            'cancel_order_count' => 0, 'cancel_amount' => '0', 'identities' => json_encode(['users' => ['809' => true], 'orders' => [], 'executions' => []]),
            'status' => 1, 'updated_at' => '2026-10-14 00:00:00']);
    }
};
$contract('2026-10-12'); $contract('2026-10-13');
$store = new TradeSummaryStore($db);
$publisher = new TradeSummaryPublisher($db, $store, null);
$fact = ['user_id' => '809', 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'win', 'order_count' => 2, 'stake_amount' => '0.200000000000000100'];
$facts = [$fact, array_merge($fact, ['user_id' => '810', 'outcome' => 'loss']), array_merge($fact, ['user_id' => '811']),
    array_merge($fact, ['user_id' => '812']), array_merge($fact, ['user_id' => '813']),
    array_merge($fact, ['user_id' => '814', 'outcome' => 'refund']), array_merge($fact, ['user_id' => null])];
$publisher->publish('2026-10-12', $facts); $publisher->publish('2026-10-13', [$fact]);
$params = ['start_date' => '2026-10-12', 'end_date' => '2026-10-13'];
$own = $store->search($params, '1')['lists'];
summaryCheck(count($own) === 2 && $own[1]['total_trading_user_count'] === 3, 'Union user count avoids double counting overlapping futures and prediction users, including robots');
summaryCheck($own[1]['trading_user_count'] === 1 && $own[1]['prediction_trading_user_count'] === 3, 'Business user counts retain their own sets');
summaryCheck($own[1]['prediction_valid_order_count'] === 6 && $own[1]['prediction_valid_stake_amount'] === '0.6000000000000003', 'Valid orders only; exact prediction decimals');
summaryCheck($own[1]['contract_trade_count'] === 3 && $own[1]['contract_trade_amount'] === '0.1000000000000001', 'Shared futures fill count and exact amount reused');
$platform = $store->search($params)['lists'];
summaryCheck($platform[1]['total_trading_user_count'] === 4, 'Platform includes direct users and robots but excludes missing sites');
$month = $store->search($params + ['period' => 'month'], '1')['lists'][0];
summaryCheck($month['total_trading_user_count'] === 3 && $month['trading_user_count'] === 1 && $month['prediction_trading_user_count'] === 3, 'Month unions identities rather than summing daily users');
summaryCheck($month['contract_trade_count'] === 6 && $month['prediction_valid_order_count'] === 8, 'Month sums disjoint UTC daily business actions');
summaryCheck($month['contract_trade_amount'] === '0.2000000000000002' && $month['prediction_valid_stake_amount'] === '0.8000000000000004', 'Monthly decimal precision retained');
summaryCheck(!isset($month['identities']) && is_string($month['source_id']), 'Private user IDs never serialized');
foreach (['day', 'month'] as $period) {
    $query = $params + ['period' => $period];
    $scoped = $store->search($query + ['source_type' => 'platform', 'source_id' => '2', 'source_keyword' => 'Two'], '11');
    summaryCheck($scoped['lists'][0]['source_id'] === '1', 'Subaccount resolves root; hostile scope parameters ignored');
    summaryCheck($store->search($query + ['source_type' => 'merchant', 'source_keyword' => 'CHANT one'])['count'] === ($period === 'day' ? 2 : 1), 'Name search is applied before counting and pagination');
    summaryCheck($store->search($query + ['source_type' => 'tenant', 'source_keyword' => '900001'])['lists'][0]['source_id'] === '900001', 'Source ID search is exact');
    summaryCheck($store->search($query + ['source_keyword' => '%'])['count'] === 0, 'Search wildcards treated literally');
}
$db->enableQueryLog(); $page = $store->search($params + ['source_type' => 'merchant', 'page_no' => 2, 'page_size' => 1]);
summaryCheck($page['count'] === 6 && count($page['lists']) === 1, 'Count and pagination include the direct child-merchant source skeleton');
foreach ($db->getQueryLog() as $query) summaryCheck(!str_contains($query['query'], 'identities'), 'Daily pages never read private user identity sets');
$db->disableQueryLog();
foreach ([['order_by' => 'identities'], ['source_type' => 'bad'], ['period' => 'week'], ['start_date' => '2026-02-30']] as $bad) {
    try { $store->search($bad); throw new RuntimeException('Unsafe filter accepted'); } catch (InvalidArgumentException $e) { $checks++; }
}
$before = $store->search($params, '1');
try { $publisher->publish('2026-10-12', [array_merge($fact, ['stake_amount' => '0.00000000000000001'])]); throw new RuntimeException('Significant precision truncated'); }
catch (InvalidArgumentException $e) { summaryCheck($store->search($params, '1') === $before, 'Excess significant precision is rejected without changing published totals'); }
try { $publisher->publish('2026-10-12', [array_merge($fact, ['stake_amount' => 0.2])]); throw new RuntimeException('Float amount accepted'); }
catch (InvalidArgumentException $e) { summaryCheck($store->search($params, '1') === $before, 'Failure preserves previously published day'); }
$publisher->publish('2026-10-14', [$fact]);
$current = $store->search(['start_date' => '2026-10-14', 'end_date' => '2026-10-14'], '1')['lists'][0];
summaryCheck($current['stat_status'] === 2, 'Missing futures source is incomplete, not fake completed zero');
$publisher->publish('2026-10-13', [array_merge($fact, ['user_id' => '814'])]);
$conflict = $store->search($params + ['source_type' => 'tenant', 'source_id' => '900002'])['lists'][0];
summaryCheck($conflict['stat_status'] === 2 && $conflict['prediction_trading_user_count'] === 1, 'Tenant follows the user site; missing tenant contract facts still mark the summary incomplete');
$crossMerchant = $store->search(['start_date' => '2026-10-13', 'end_date' => '2026-10-13'], '1')['lists'][0];
summaryCheck($crossMerchant['stat_status'] === 1 && $crossMerchant['prediction_trading_user_count'] === 1,
    'A different user site does not prevent attribution to users.agent_id');
CarbonImmutable::setTestNow();
echo "PASS {$checks} platform trade summary checks\n";
