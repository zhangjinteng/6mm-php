<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\FuturesReports\SourceDirectory;
use SixMm\Shared\FuturesReports\UserNetPnlReportAggregator;
use SixMm\Shared\FuturesReports\UserNetPnlReportPublisher;
use SixMm\Shared\FuturesReports\UserNetPnlReportSchema;
use SixMm\Shared\FuturesReports\UserNetPnlReportStore;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesFactRedisRepository;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesHourlyFactService;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkUserPnl(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function userPnlSource(array $rows, string $type, string $id): array
{
    foreach ($rows as $row) {
        if ($row['source_type'] === $type && (string) $row['source_id'] === $id) return $row;
    }
    throw new RuntimeException('Missing source ' . $type . ':' . $id);
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $capsule->getConnection();
$capsule->setAsGlobal();
$capsule->bootEloquent();
$schema = $connection->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->string('agent_code'); $t->string('agent_name');
    $t->string('user_name'); $t->bigInteger('home_site_id'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable(); $t->bigInteger('agent_id');
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$connection->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'agent_code' => 'M1', 'agent_name' => 'Merchant 1', 'user_name' => 'm1', 'home_site_id' => 100],
    ['id' => 2, 'pid' => 0, 'agent_code' => 'M2', 'agent_name' => 'Merchant 2', 'user_name' => 'm2', 'home_site_id' => 200],
]);
$connection->table('wl_sites')->insert([['site_id' => 100, 'tenant_id' => 10], ['site_id' => 200, 'tenant_id' => 20]]);
$connection->table('wl_tenants')->insert([['tenant_id' => 10, 'tenant_name' => 'Tenant A'], ['tenant_id' => 20, 'tenant_name' => 'Tenant B']]);
$connection->table('users')->insert([
    ['user_id' => 11, 'home_site_id' => 100, 'agent_id' => 1],
    ['user_id' => 12, 'home_site_id' => 100, 'agent_id' => 0],
    ['user_id' => 13, 'home_site_id' => 100, 'agent_id' => 1],
    ['user_id' => 21, 'home_site_id' => 200, 'agent_id' => 2],
    ['user_id' => 31, 'home_site_id' => 0, 'agent_id' => 1],
]);
$directory = new SourceDirectory($connection);
$origin = $directory->load(['11', '12', '13', '21', '31']);
$settlement = static fn (int $agent, int $user, string $pnl, string $order): array => [
    'agent_id' => $agent, 'user_id' => $user, 'user_type' => 2, 'settle_asset' => 'USDT',
    'close_order_ids' => [$order], 'close_order_count' => 1, 'close_amount' => '100.00',
    'realized_pnl' => $pnl, 'funding_fee' => '0', 'liquidation_fee' => '0',
];
$trade = static fn (int $agent, int $user, string $fee): array => [
    'agent_id' => $agent, 'user_id' => $user, 'user_type' => 2, 'settle_asset' => 'USDT',
    'trading_fee' => $fee,
];
$facts = [
    ['kind' => 'settlement', 'row' => $settlement(1, 11, '10.00', '1001')],
    ['kind' => 'settlement', 'row' => $settlement(0, 12, '-5.00', '1002')],
    ['kind' => 'settlement', 'row' => $settlement(2, 21, '4.00', '2001')],
    ['kind' => 'settlement', 'row' => $settlement(1, 11, '1.00', '1001')],
    ['kind' => 'settlement', 'row' => $settlement(1, 31, '999.00', '9999')],
    ['kind' => 'trade', 'row' => $trade(1, 11, '2.00')],
    ['kind' => 'trade', 'row' => $trade(1, 13, '1.00')],
    ['kind' => 'trade', 'row' => $trade(0, 12, '1.00')],
    ['kind' => 'trade', 'row' => $trade(2, 21, '0.50')],
];
$aggregator = new UserNetPnlReportAggregator();
$rows = $aggregator->build('2026-10-10', $facts, $origin, true);
$merchant = userPnlSource($rows, 'merchant', '1');
$tenant = userPnlSource($rows, 'tenant', '10');
$platform = userPnlSource($rows, 'platform', '0');
checkUserPnl($merchant['settled_user_count'] === 1 && $merchant['close_order_count'] === 1, 'Merchant users and duplicate order IDs must be deduplicated');
checkUserPnl($merchant['true_net_pnl'] === '8.0000000000000000', 'Merchant net PnL must include fees from users without closed orders');
checkUserPnl($tenant['settled_user_count'] === 2 && $tenant['true_net_pnl'] === '2.0000000000000000', 'Tenant includes its direct user but not fee-only users in settlement count');
checkUserPnl(userPnlSource($rows, 'tenant', '20')['true_net_pnl'] === '3.5000000000000000', 'Tenants stay isolated');
checkUserPnl($platform['settled_user_count'] === 3 && $platform['true_net_pnl'] === '5.5000000000000000', 'Platform includes merchants and direct users');
UserNetPnlReportSchema::create($connection);
UserNetPnlReportSchema::create($connection);
$store = new UserNetPnlReportStore($connection);
$store->replace('2026-10-10', $rows);
checkUserPnl($store->search(['start_date' => '2026-10-10', 'end_date' => '2026-10-10'])['lists'][0]['source_type'] === 'platform', 'Default scope is platform');
checkUserPnl($store->search(['source_type' => 'tenant', 'start_date' => '2026-10-10', 'end_date' => '2026-10-10'])['count'] === 2, 'Tenant query has two rows');
checkUserPnl($store->search(['source_type' => 'merchant', 'start_date' => '2026-10-10', 'end_date' => '2026-10-10'], '1')['lists'][0]['source_id'] === '1', 'Merchant scope comes from server, not request');
$store->replace('2026-10-11', $aggregator->build('2026-10-11', $facts, $origin, false));
$monthly = $store->search(['period' => 'month', 'start_date' => '2026-10-10', 'end_date' => '2026-10-11'])['lists'][0];
checkUserPnl($monthly['settled_user_count'] === 6 && $monthly['stat_status'] === 0, 'Month sums daily people-times and stays processing');
$failed = false;
try { $store->replace('2026-10-10', [$rows[0], $rows[0]]); } catch (Throwable $e) { $failed = true; }
checkUserPnl($failed && $store->search(['start_date' => '2026-10-10', 'end_date' => '2026-10-10'])['count'] === 1, 'Failed replacement retains old data');
$buckets = new class extends AgentFuturesFactRedisRepository {
    public array $bucket = [];
    public function __construct() {}
    public function getHour(CarbonImmutable $hourStart): ?array { return $this->bucket ?: null; }
};
$buckets->bucket = [
    'status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
    'agent_ids' => [0, 1, 2], 'window_start' => '2026-10-12 00:00:00', 'window_end' => '2026-10-12 01:00:00',
    'user_pnl_rows' => [$settlement(1, 11, '10.00', '1001'), $settlement(0, 12, '-5.00', '1002')],
    'rows' => [$trade(1, 11, '2.00'), $trade(0, 12, '1.00')],
];
(new UserNetPnlReportPublisher($buckets, $directory, $store))->publish('2026-10-12', 1, false, [0, 1, 2]);
checkUserPnl($store->search(['start_date' => '2026-10-12', 'end_date' => '2026-10-12'])['lists'][0]['settled_user_count'] === 2, 'Publisher reads both fact streams');
$buckets->bucket['agent_ids'] = [1, 2];
try { (new UserNetPnlReportPublisher($buckets, $directory, $store))->publish('2026-10-12', 1, false, [0, 1, 2]); throw new RuntimeException('Accepted incomplete scope'); }
catch (RuntimeException $e) { checkUserPnl(str_contains($e->getMessage(), '小时桶'), 'Scope mismatch rejects publication'); }
echo "Shared user net PnL report: source scopes, fees, monthly semantics, query, publisher, atomic replacement passed.\n";
