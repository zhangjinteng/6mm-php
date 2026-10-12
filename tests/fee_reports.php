<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\FuturesReports\{FeeReportAggregator, FeeReportSchema, FeeReportStore, FeeReportPublisher, SourceDirectory};
use SixMm\Shared\FuturesReports\Legacy\{AgentFuturesFactRedisRepository, AgentFuturesHourlyFactService};

require dirname(__DIR__) . '/vendor/autoload.php';
function feeCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function feeSource(array $rows, string $type, string $id): array {
    foreach ($rows as $row) if ($row['source_type'] === $type && $row['source_id'] === $id && $row['symbol'] === '') return $row;
    throw new RuntimeException('Missing fee source');
}
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal(); $capsule->bootEloquent(); $db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id');
    $t->string('agent_code'); $t->string('agent_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void { $t->bigInteger('user_id'); $t->bigInteger('home_site_id'); $t->bigInteger('agent_id'); });
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$db->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'home_site_id' => 100, 'agent_code' => 'MER1', 'agent_name' => 'Merchant One'],
    ['id' => 2, 'pid' => 0, 'home_site_id' => 200, 'agent_code' => 'MER2', 'agent_name' => 'Merchant Two'],
    ['id' => 11, 'pid' => 1, 'home_site_id' => 100, 'agent_code' => 'SUB1', 'agent_name' => 'Subaccount'],
]);
$db->table('wl_sites')->insert([['site_id' => 100, 'tenant_id' => 900001], ['site_id' => 200, 'tenant_id' => 900002]]);
$db->table('wl_tenants')->insert([['tenant_id' => 900001, 'tenant_name' => 'White Label One'], ['tenant_id' => 900002, 'tenant_name' => 'White Label Two']]);
$db->table('users')->insert([
    ['user_id' => 809, 'home_site_id' => 100, 'agent_id' => 1],
    ['user_id' => 810, 'home_site_id' => 200, 'agent_id' => 2],
    ['user_id' => 811, 'home_site_id' => 100, 'agent_id' => 0],
    ['user_id' => 812, 'home_site_id' => 0, 'agent_id' => 1],
    ['user_id' => 813, 'home_site_id' => 200, 'agent_id' => 1],
]);
FeeReportSchema::create($db); FeeReportSchema::create($db);
$directory = (new SourceDirectory($db))->load(['809', '810', '811', '812', '813']);
$base = ['agent_id' => 1, 'user_id' => '809', 'user_type' => 2, 'symbol' => 'BTCUSDT', 'settle_asset' => 'USDT',
    'fee_count' => 2, 'maker_trade_amount' => '9007199254740993.0000000000000001', 'taker_trade_amount' => '20',
    'maker_handling_fee' => '0.1000000000000001', 'taker_handling_fee' => '0.2',
    'maker_commission' => '999', 'trading_fee' => '888', 'total_handling_fee' => '777'];
$other = array_merge($base, ['fee_count' => 1, 'maker_trade_amount' => '10', 'taker_trade_amount' => '30',
    'maker_handling_fee' => '0.05', 'taker_handling_fee' => '0.07']);
$free = array_merge($base, ['user_id' => '810', 'agent_id' => 2, 'fee_count' => 0,
    'maker_trade_amount' => '50', 'taker_trade_amount' => '0', 'maker_handling_fee' => '0', 'taker_handling_fee' => '0']);
$direct = array_merge($other, ['agent_id' => 0, 'user_id' => '811']);
$agg = new FeeReportAggregator();
$rows = $agg->build('2026-10-09', [$base, $other, $free, $direct, array_merge($base, ['user_type' => 1]),
    array_merge($base, ['settle_asset' => 'BTC']), array_merge($base, ['user_id' => '812']), array_merge($base, ['user_id' => '999'])], $directory, true);
$merchant = feeSource($rows, 'merchant', '1');
feeCheck($merchant['fee_user_count'] === 1 && $merchant['fee_count'] === 3, 'Fee users deduplicate across hours while debit records sum');
feeCheck($merchant['maker_trade_amount'] === '9007199254741003.0000000000000001', 'Turnover preserves decimal precision');
feeCheck($merchant['total_handling_fee'] === '0.4200000000000001', 'Total handling fee equals Maker plus Taker, not commissions/trading_fee');
feeCheck(feeSource($rows, 'merchant', '2')['fee_user_count'] === 0 && feeSource($rows, 'merchant', '2')['maker_trade_amount'] === '50.0000000000000000', 'Zero-fee turnover remains without creating charged users');
feeCheck(feeSource($rows, 'tenant', '900001')['fee_user_count'] === 2, 'White label includes direct users');
feeCheck(feeSource($rows, 'platform', '0')['fee_user_count'] === 2, 'Only eligible simulated-USDT users count');
$conflict = $agg->build('2026-10-09', [array_merge($base, ['user_id' => '813'])], $directory, true);
feeCheck(feeSource($conflict, 'merchant', '1')['fee_count'] === 0 && feeSource($conflict, 'tenant', '900002')['status'] === 2, 'Merchant attribution mismatch fails closed and marks affected tenant incomplete');
foreach ([['fee_count' => -1], ['fee_count' => '1.1'], ['maker_handling_fee' => 0.1], ['taker_handling_fee' => '-1']] as $invalid) {
    try { $agg->build('2026-10-09', [array_merge($base, $invalid)], $directory, true); throw new RuntimeException('Invalid metric accepted'); }
    catch (InvalidArgumentException $e) {}
}
$store = new FeeReportStore($db); $store->replace('2026-10-09', $rows);
$store->replace('2026-10-10', $agg->build('2026-10-10', [$other], $directory, false));
$params = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'source_type' => 'merchant', 'source_id' => '1'];
$month = $store->search($params + ['period' => 'month'])['lists'][0];
feeCheck($month['fee_user_count'] === 1 && $month['fee_count'] === 4, 'Month unions users rather than summing daily users');
feeCheck($month['total_handling_fee'] === '0.5400000000000001', 'Monthly exact total fee formula');
feeCheck(!isset($month['identities']) && is_string($month['source_id']) && $month['stat_status'] === 0, 'Safe ID/private set/status serialization');
foreach (['day', 'month'] as $period) {
    $q = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'period' => $period];
    feeCheck($store->search($q + ['source_keyword' => 'CHANT one'])['count'] === ($period === 'day' ? 2 : 1), 'Name search is case-insensitive literal substring before pagination');
    feeCheck($store->search($q + ['source_keyword' => '900001'])['lists'][0]['source_id'] === '900001', 'Source ID exact match');
    feeCheck($store->search($q + ['source_keyword' => '%'])['count'] === 0, 'Wildcard characters are literal');
    $scoped = $store->search($q + ['source_type' => 'platform', 'source_id' => '2', 'source_keyword' => 'Two'], '11', '100');
    feeCheck($scoped['lists'][0]['source_id'] === '1', 'Subaccount scope ignores hostile external source parameters');
    try { $store->search($q, '1', '200'); throw new RuntimeException('Cross-site scope accepted'); } catch (InvalidArgumentException $e) {}
}
$db->enableQueryLog(); $page = $store->search($params + ['page_no' => 2, 'page_size' => 1]);
feeCheck($page['count'] === 2 && count($page['lists']) === 1, 'Daily count/page share filters');
foreach ($db->getQueryLog() as $query) feeCheck(!str_contains($query['query'], 'identities'), 'Daily never reads user identity JSON');
$db->disableQueryLog();
feeCheck($store->search($params + ['symbol' => 'eth'])['lists'][0]['fee_count'] === 0, 'Unknown symbol preserves source/date skeleton');
feeCheck($store->search($params + ['symbol' => 'btc'])['lists'][0]['fee_count'] === 1, 'Base ticker matches full USDT symbol');
try { $store->search(['order_by' => 'identities']); throw new RuntimeException('Unsafe sort accepted'); } catch (InvalidArgumentException $e) {}
$failed = false; try { $store->replace('2026-10-09', [$rows[0], $rows[0]]); } catch (Throwable $e) { $failed = true; }
feeCheck($failed && $store->search($params)['count'] === 2, 'Failed replacement retains previous data');
$buckets = new class extends AgentFuturesFactRedisRepository {
    public bool $valid = true; public array $rows = []; public function __construct() {}
    public function getHour(CarbonImmutable $hour): ?array { return ['status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
        'agent_ids' => $this->valid ? [0, 1, 2] : [1, 2], 'window_start' => $hour->format('Y-m-d H:i:s'),
        'window_end' => $hour->addHour()->format('Y-m-d H:i:s'), 'rows' => $this->rows]; }
};
$buckets->rows = [$base]; $publisher = new FeeReportPublisher($buckets, new SourceDirectory($db), $store);
$publisher->publish('2026-10-09', 1, true, [0, 1, 2]); $buckets->valid = false;
try { $publisher->publish('2026-10-09', 1, true, [0, 1, 2]); throw new RuntimeException('Wrong cache scope accepted'); }
catch (RuntimeException $e) { feeCheck(str_contains($e->getMessage(), '小时桶'), 'Wrong scope prevents cache publication'); }
feeCheck($store->search($params)['lists'][1]['fee_count'] === 2, 'Invalid cache keeps old publication');
echo "Fee reports: handling-fee semantics, scopes, decimals, monthly union, filters, paging and cache safety passed.\n";
