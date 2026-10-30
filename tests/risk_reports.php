<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\FuturesReports\{RiskReportAggregator, RiskReportSchema, RiskReportStore, RiskReportPublisher, SourceDirectory};
use SixMm\Shared\FuturesReports\Legacy\{AgentFuturesFactRedisRepository, AgentFuturesHourlyFactService};

require dirname(__DIR__) . '/vendor/autoload.php';
function riskCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function riskSource(array $rows, string $type, string $id): array {
    foreach ($rows as $row) if ($row['source_type'] === $type && $row['source_id'] === $id && $row['symbol'] === '') return $row;
    throw new RuntimeException('Missing risk source');
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
RiskReportSchema::create($db); RiskReportSchema::create($db);
$directory = (new SourceDirectory($db))->load(['809', '810', '811', '812', '813']);
$base = ['agent_id' => 1, 'user_id' => '809', 'user_type' => 2, 'symbol' => 'BTCUSDT', 'settle_asset' => 'USDT',
    'liquidation_position_ids' => ['9007199254740993'], 'liquidation_position_amounts' => ['9007199254740993' => '100.0000000000000001'],
    'liquidation_trade_amount' => '98', 'liquidation_realized_pnl' => '-12.34', 'liquidation_fee' => '0.1',
    'bankruptcy_event_amounts' => ['ledger-1' => '2.0000000000000001'], 'bankruptcy_loss' => '2.0000000000000001',
    'uncollected_liquidation_fee' => '4', 'uncollected_trading_fee' => '0.06'];
$later = array_merge($base, ['liquidation_position_amounts' => ['9007199254740993' => '120'], 'liquidation_trade_amount' => '2',
    'liquidation_realized_pnl' => '-1', 'liquidation_fee' => '0.05', 'bankruptcy_event_amounts' => ['ledger-1' => '2.5'],
    'uncollected_liquidation_fee' => '0.25', 'uncollected_trading_fee' => '0.01']);
$direct = array_merge($base, ['agent_id' => 0, 'user_id' => '811', 'liquidation_position_ids' => ['1002'],
    'liquidation_position_amounts' => ['1002' => '50'], 'bankruptcy_event_amounts' => [], 'bankruptcy_loss' => '0']);
$agg = new RiskReportAggregator();
foreach ([array_merge($base, ['liquidation_position_amounts' => []]), array_merge($base, ['liquidation_fee' => 0.1])] as $invalid) {
    try { $agg->build('2026-10-09', [$invalid], $directory, true); throw new RuntimeException('Accepted incomplete or floating-point metrics'); }
    catch (InvalidArgumentException $e) {}
}
$rows = $agg->build('2026-10-09', [$base, $later, $direct, array_merge($base, ['user_type' => 1]),
    array_merge($base, ['settle_asset' => 'BTC']), array_merge($base, ['user_id' => '812']), array_merge($base, ['user_id' => '999'])], $directory, true);
$merchant = riskSource($rows, 'merchant', '1');
riskCheck($merchant['liquidation_user_count'] === 1 && $merchant['liquidation_count'] === 1, 'Risk users and positions deduplicate across hours');
riskCheck($merchant['liquidation_position_amount'] === '120.0000000000000000' && $merchant['liquidation_trade_amount'] === '198.0000000000000000', 'Position notionals union while actual trade flows sum across all user types');
riskCheck($merchant['liquidation_realized_pnl'] === '-25.6800000000000000' && $merchant['liquidation_fee'] === '0.2500000000000000', 'Signed PnL and fees retain exact decimals');
riskCheck($merchant['bankruptcy_count'] === 1 && $merchant['bankruptcy_loss'] === '2.5000000000000000', 'Final bankruptcy loss uses the unique ledger event maximum');
riskCheck(riskSource($rows, 'tenant', '900001')['liquidation_user_count'] === 2, 'White-label includes direct users');
riskCheck(riskSource($rows, 'platform', '0')['liquidation_user_count'] === 2, 'User type is unrestricted; non-USDT and missing/non-positive origins are excluded');
$conflict = $agg->build('2026-10-09', [array_merge($base, ['user_id' => '813'])], $directory, true);
riskCheck(riskSource($conflict, 'merchant', '1')['liquidation_count'] === 1 && riskSource($conflict, 'tenant', '900002')['status'] === 1,
    'User agent and user site independently determine complete report scopes');
$store = new RiskReportStore($db); $store->replace('2026-10-09', $rows);
$store->replace('2026-10-10', $agg->build('2026-10-10', [$base], $directory, false));
$params = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'source_type' => 'merchant', 'source_id' => '1'];
$month = $store->search($params + ['period' => 'month'])['lists'][0];
riskCheck($month['liquidation_user_count'] === 1 && $month['liquidation_count'] === 1 && $month['bankruptcy_count'] === 1, 'Monthly users, positions and bankruptcy events union rather than sum');
riskCheck($month['liquidation_trade_amount'] === '296.0000000000000000' && $month['bankruptcy_loss'] === '2.5000000000000000', 'Monthly flows and ledger dedup preserve their different meanings');
riskCheck($month['stat_status'] === 0 && !isset($month['identities']) && is_string($month['source_id']), 'Monthly status and public serialization are compatible');
foreach (['day', 'month'] as $period) {
    $q = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'period' => $period];
    $named = $store->search($q + ['source_keyword' => 'chant ONE']);
    riskCheck($named['count'] === ($period === 'day' ? 2 : 1) && $named['lists'][0]['source_id'] === '1', 'Source name is a case-insensitive partial filter');
    riskCheck($store->search($q + ['source_keyword' => '900001'])['lists'][0]['source_id'] === '900001', 'Source ID exact search');
    riskCheck($store->search($q + ['source_keyword' => '%'])['count'] === 0, 'LIKE wildcards are literal');
    $scoped = $store->search($q + ['source_type' => 'platform', 'source_id' => '2', 'source_keyword' => 'Two'], '11', '100');
    riskCheck($scoped['lists'][0]['source_id'] === '1', 'Server-derived root merchant/site override hostile query scope');
    $own = $store->search($q, '1', '200');
    riskCheck($own['lists'][0]['source_type'] === 'merchant' && $own['lists'][0]['source_id'] === '1', 'Site metadata does not block own published risk reports');
}
$db->enableQueryLog();
$daily = $store->search($params + ['page_no' => 2, 'page_size' => 1]);
riskCheck($daily['count'] === 2 && count($daily['lists']) === 1, 'Daily count and page share filters');
foreach ($db->getQueryLog() as $query) riskCheck(!str_contains($query['query'], 'identities'), 'Daily queries never fetch private identity JSON');
$db->disableQueryLog();
riskCheck($store->search($params + ['symbol' => 'eth'])['lists'][0]['liquidation_count'] === 0, 'Unknown contract retains the date/source skeleton');
riskCheck($store->search($params + ['symbol' => 'btc'])['lists'][0]['liquidation_count'] === 1, 'Base ticker and complete contract both work');
try { $store->search(['order_by' => 'identities']); throw new RuntimeException('Accepted unsafe sort'); } catch (InvalidArgumentException $e) {}
$failed = false; try { $store->replace('2026-10-09', [$rows[0], $rows[0]]); } catch (Throwable $e) { $failed = true; }
riskCheck($failed && $store->search($params)['count'] === 2, 'Failed replacement keeps the previously published rows');
$buckets = new class extends AgentFuturesFactRedisRepository {
    public bool $valid = true; public array $rows = []; public function __construct() {}
    public function getHour(CarbonImmutable $hour): ?array { return ['status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
        'agent_ids' => $this->valid ? [0, 1, 2] : [1, 2], 'window_start' => $hour->format('Y-m-d H:i:s'),
        'window_end' => $hour->addHour()->format('Y-m-d H:i:s'), 'rows' => $this->rows]; }
};
$buckets->rows = [$base]; $publisher = new RiskReportPublisher($buckets, new SourceDirectory($db), $store);
$publisher->publish('2026-10-09', 1, true, [0, 1, 2]); $buckets->valid = false;
try { $publisher->publish('2026-10-09', 1, true, [0, 1, 2]); throw new RuntimeException('Accepted wrong cache scope'); } catch (RuntimeException $e) { riskCheck(str_contains($e->getMessage(), '小时桶'), 'Invalid cache prevents publication'); }
riskCheck($store->search($params)['lists'][1]['liquidation_count'] === 1, 'Invalid cache retains prior publication');
echo "Risk reports: scope, decimal metrics, identity union, source search, pagination and atomic publication passed.\n";
