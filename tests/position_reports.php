<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Carbon\CarbonImmutable;
use SixMm\Shared\FuturesReports\PositionReportAggregator;
use SixMm\Shared\FuturesReports\PositionReportPublisher;
use SixMm\Shared\FuturesReports\PositionReportSchema;
use SixMm\Shared\FuturesReports\PositionReportStore;
use SixMm\Shared\FuturesReports\PositionReportLegacyBackfill;
use SixMm\Shared\FuturesReports\SourceDirectory;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesFactRedisRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
if (!function_exists('config')) {
    function config($key, $default = null) { return $default; }
}

function checkPosition(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function positionRow(array $rows, string $type, string $id, string $symbol = ''): array {
    foreach ($rows as $row) if ($row['source_type'] === $type && (string) $row['source_id'] === $id && $row['symbol'] === $symbol) return $row;
    throw new RuntimeException('Position source not found');
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$redis = new class {
    public array $values = [];
    public function setex(string $key, int $ttl, string $value): bool { $this->values[$key] = $value; return true; }
    public function rename(string $from, string $to): bool { $this->values[$to] = $this->values[$from]; unset($this->values[$from]); return true; }
    public function get(string $key): ?string { return $this->values[$key] ?? null; }
};
$cache = new AgentFuturesFactRedisRepository($redis, 3600, 'position-test', 'futures:test');
$cache->putBoundarySnapshot(CarbonImmutable::parse('2026-10-10', 'UTC'), ['status' => 'completed', 'snapshot_at' => '2026-10-10 00:00:00', 'rows' => []]);
$cache->putSnapshot(CarbonImmutable::parse('2026-10-10', 'UTC'), ['status' => 'completed', 'snapshot_at' => '2026-10-10 10:00:00', 'rows' => []]);
checkPosition($cache->getBoundarySnapshot(CarbonImmutable::parse('2026-10-10', 'UTC'))['snapshot_at'] === '2026-10-10 00:00:00',
    'Later live snapshots must not overwrite the UTC-boundary snapshot');
PositionReportSchema::create($db);
$schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->string('agent_code'); $t->string('agent_name');
    $t->string('user_name'); $t->bigInteger('home_site_id')->nullable(); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable(); $t->bigInteger('agent_id');
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$schema->create('admin_agent_futures_user_symbol_daily', static function (Blueprint $t): void {
    $t->date('stat_date'); $t->bigInteger('agent_id'); $t->integer('user_type'); $t->bigInteger('user_id'); $t->string('symbol');
    foreach (['long_position_amount', 'short_position_amount', 'closing_position_amount', 'occupied_margin', 'unrealized_pnl'] as $field) $t->string($field);
    $t->timestamp('position_snapshot_at')->nullable();
});
$db->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'agent_code' => 'M1', 'agent_name' => 'Merchant 1', 'user_name' => 'm1', 'home_site_id' => 100],
    ['id' => 2, 'pid' => 0, 'agent_code' => 'M2', 'agent_name' => 'Merchant 2', 'user_name' => 'm2', 'home_site_id' => 101],
]);
$db->table('users')->insert([
    ['user_id' => 11, 'home_site_id' => 100, 'agent_id' => 1],
    ['user_id' => 12, 'home_site_id' => 101, 'agent_id' => 2],
    ['user_id' => 13, 'home_site_id' => 100, 'agent_id' => 0],
    ['user_id' => 14, 'home_site_id' => 101, 'agent_id' => 1],
]);
$db->table('wl_sites')->insert([['site_id' => 100, 'tenant_id' => 7], ['site_id' => 101, 'tenant_id' => 7]]);
$db->table('wl_tenants')->insert(['tenant_id' => 7, 'tenant_name' => 'White label']);
$directory = new SourceDirectory($db);
$snapshot = static function (string $at, string $amount): array {
    $base = ['agent_id' => 1, 'user_type' => 2, 'user_id' => '11', 'symbol' => 'BTCUSDT',
        'long_position_amount' => $amount, 'short_position_amount' => '0', 'closing_position_amount' => $amount,
        'occupied_margin' => '10', 'unrealized_pnl' => '-1'];
    return ['status' => 'completed', 'snapshot_at' => $at, 'rows' => [
        $base, array_merge($base, ['symbol' => 'ETHUSDT', 'long_position_amount' => '0', 'short_position_amount' => '40', 'closing_position_amount' => '40', 'occupied_margin' => '20', 'unrealized_pnl' => '2']),
        array_merge($base, ['agent_id' => 2, 'user_id' => '12', 'symbol' => 'BTCUSDT', 'long_position_amount' => '25', 'closing_position_amount' => '25', 'occupied_margin' => '5']),
        array_merge($base, ['agent_id' => 0, 'user_id' => '13', 'symbol' => 'BTCUSDT', 'long_position_amount' => '5', 'closing_position_amount' => '5', 'occupied_margin' => '1']),
        array_merge($base, ['user_id' => '14', 'symbol' => 'BTCUSDT', 'long_position_amount' => '999', 'closing_position_amount' => '999']),
    ]];
};
$store = new PositionReportStore($db);
$publisher = new PositionReportPublisher($directory, $store);
$publisher->publish('2026-10-09', $snapshot('2026-10-10 00:00:01', '100'), true);
$merchant = $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09'], '1')['lists'][0];
checkPosition((int) $merchant['position_user_count'] === 1 && (int) $merchant['long_position_user_count'] === 1
    && (int) $merchant['short_position_user_count'] === 1, 'One user may hold both sides and multiple symbols');
checkPosition($merchant['closing_position_amount'] === '140', 'Merchant must not include another-site user');
checkPosition($merchant['average_leverage'] === '4.66666666', 'Leverage uses total notional / occupied margin');
$tenant = $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'source_type' => 'tenant'])['lists'][0];
checkPosition((int) $tenant['position_user_count'] === 4 && $tenant['closing_position_amount'] === '1169',
    'Tenant follows user site origin even when merchant attribution conflicts');
checkPosition((int) $tenant['stat_status'] === 2, 'Conflicting merchant attribution marks the tenant partial');
$btc = $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'symbol' => 'BTC', 'source_type' => 'platform'])['lists'][0];
checkPosition((int) $btc['position_user_count'] === 4 && $btc['closing_position_amount'] === '1129', 'Symbol filter uses its own snapshot counts');
$store->replace('2026-10-10', (new PositionReportAggregator())->build('2026-10-10',
    $snapshot('2026-10-10 12:00:00', '50'), $directory->load(['11', '12', '13', '14']), false));
$month = $store->search(['period' => 'month', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10'], '1')['lists'][0];
checkPosition($month['stat_date'] === '2026-10' && $month['closing_position_amount'] === '90'
    && (int) $month['stat_status'] === 0, 'Monthly report uses the latest snapshot, not a sum');
try { $publisher->publish('2026-10-08', $snapshot('2026-10-10 12:00:00', '50'), true); throw new RuntimeException('Accepted a live snapshot as historical'); }
catch (InvalidArgumentException $e) {}
checkPosition($store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'source_type' => 'merchant', 'source_keyword' => 'Merchant 2'])['count'] === 2,
    'Platform source search filters the published scope');
$db->table('admin_agent_futures_user_symbol_daily')->insert([
    ['stat_date' => '2026-10-08', 'agent_id' => 1, 'user_type' => 2, 'user_id' => 11, 'symbol' => 'BTCUSDT',
        'long_position_amount' => '80', 'short_position_amount' => '0', 'closing_position_amount' => '80',
        'occupied_margin' => '8', 'unrealized_pnl' => '1', 'position_snapshot_at' => '2026-10-09 00:00:00'],
    ['stat_date' => '2026-10-07', 'agent_id' => 1, 'user_type' => 2, 'user_id' => 11, 'symbol' => 'BTCUSDT',
        'long_position_amount' => '70', 'short_position_amount' => '0', 'closing_position_amount' => '70',
        'occupied_margin' => '7', 'unrealized_pnl' => '1', 'position_snapshot_at' => '2026-10-07 15:00:00'],
]);
$backfill = new PositionReportLegacyBackfill($db, $directory, $store);
checkPosition($backfill->backfill('2026-10-08') > 0 && $backfill->backfill('2026-10-08') === 0,
    'Actual UTC-boundary merchant snapshots backfill once without replacing published data');
checkPosition($store->search(['start_date' => '2026-10-08', 'end_date' => '2026-10-08'], '2')['count'] === 0,
    'Legacy backfill must not fabricate a zero snapshot for an unobserved merchant');
checkPosition($backfill->backfill('2026-10-07') === 0,
    'An intraday historical position must never be treated as the day-end snapshot');
echo "position reports ok\n";
