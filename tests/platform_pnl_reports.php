<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Carbon\CarbonImmutable;
use SixMm\Shared\FuturesReports\PlatformPnlReportAggregator;
use SixMm\Shared\FuturesReports\PlatformPnlReportPublisher;
use SixMm\Shared\FuturesReports\PlatformPnlReportSchema;
use SixMm\Shared\FuturesReports\PlatformPnlReportStore;
use SixMm\Shared\FuturesReports\SourceDirectory;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesFactRedisRepository;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesHourlyFactService;
use SixMm\Shared\PredictionReports\PredictionUserPnlPublisher;
use SixMm\Shared\PredictionReports\PredictionUserPnlSchema;
use SixMm\Shared\PredictionReports\PredictionUserPnlStore;

require dirname(__DIR__) . '/vendor/autoload.php';

function platformPnlCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function platformPnlRow(array $rows, string $type, string $id): array
{
    foreach ($rows as $row) if ($row['source_type'] === $type && (string) $row['source_id'] === $id) return $row;
    throw new RuntimeException("Missing {$type}:{$id}");
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id');
    $t->string('agent_code'); $t->string('agent_name'); $t->string('user_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->bigInteger('home_site_id'); $t->bigInteger('agent_id'); $t->integer('user_type');
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$db->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'home_site_id' => 100, 'agent_code' => 'M1', 'agent_name' => 'Merchant One', 'user_name' => 'one'],
    ['id' => 2, 'pid' => 0, 'home_site_id' => 200, 'agent_code' => 'M2', 'agent_name' => 'Merchant Two', 'user_name' => 'two'],
]);
$db->table('wl_sites')->insert([['site_id' => 100, 'tenant_id' => 7], ['site_id' => 200, 'tenant_id' => 8]]);
$db->table('wl_tenants')->insert([['tenant_id' => 7, 'tenant_name' => 'White label 7'], ['tenant_id' => 8, 'tenant_name' => 'White label 8']]);
$db->table('users')->insert([
    ['user_id' => 11, 'home_site_id' => 100, 'agent_id' => 1, 'user_type' => 2],
    ['user_id' => 12, 'home_site_id' => 100, 'agent_id' => 0, 'user_type' => 2],
    ['user_id' => 21, 'home_site_id' => 200, 'agent_id' => 2, 'user_type' => 1],
]);
PlatformPnlReportSchema::create($db);
PredictionUserPnlSchema::create($db);
$directory = (new SourceDirectory($db))->load(['11', '12', '21']);
$facts = [
    ['agent_id' => 1, 'user_id' => 11, 'user_type' => 2, 'settle_asset' => 'USDT',
        'realized_pnl' => '-10', 'trading_fee' => '2', 'liquidation_fee' => '1', 'funding_fee' => '-0.5'],
    ['agent_id' => 0, 'user_id' => 12, 'user_type' => 2, 'settle_asset' => 'USDT',
        'realized_pnl' => '4', 'trading_fee' => '1', 'liquidation_fee' => '0', 'funding_fee' => '0.25'],
    ['agent_id' => 2, 'user_id' => 21, 'user_type' => 1, 'settle_asset' => 'USDT',
        'realized_pnl' => '3', 'trading_fee' => '0.5', 'liquidation_fee' => '0', 'funding_fee' => '0'],
];
$aggregator = new PlatformPnlReportAggregator();
$rows = $aggregator->build('2026-10-12', $facts, $directory, true);
platformPnlCheck(platformPnlRow($rows, 'merchant', '1')['contract_total_pnl'] === '12.5000000000000000', 'Merchant formula matches Agent report');
platformPnlCheck(platformPnlRow($rows, 'tenant', '7')['contract_total_pnl'] === '9.7500000000000000', 'Tenant includes direct users');
platformPnlCheck(platformPnlRow($rows, 'platform', '0')['contract_total_pnl'] === '7.2500000000000000', 'Platform includes both merchants and direct users');
$unknown = $facts[0]; $unknown['user_id'] = 999;
$excluded = platformPnlRow($aggregator->build('2026-10-12', [$unknown], $directory, true), 'platform', '0');
platformPnlCheck($excluded['status'] === 1 && bccomp($excluded['contract_total_pnl'], '0', 16) === 0,
    'Users without an eligible user profile are excluded rather than added as partial totals');
$unmappedDirectory = $directory;
$unmappedDirectory['users']['11']['site_id'] = '999';
platformPnlCheck(platformPnlRow($aggregator->build('2026-10-12', [$facts[0]], $unmappedDirectory, true), 'platform', '0')['status'] === 2,
    'An eligible positive site without a white-label mapping still marks totals incomplete');
$store = new PlatformPnlReportStore($db);
$store->replace('2026-10-12', $rows);
$prediction = new PredictionUserPnlPublisher($db, null, new PredictionUserPnlStore($db));
$prediction->publish('2026-10-12', [
    ['user_id' => 11, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'loss',
        'order_count' => 1, 'stake_amount' => '5', 'return_amount' => '0'],
    ['user_id' => 12, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'win',
        'order_count' => 1, 'stake_amount' => '5', 'return_amount' => '8'],
]);
$result = $store->search(['start_date' => '2026-10-12', 'end_date' => '2026-10-12']);
platformPnlCheck($result['count'] === 1 && bccomp($result['lists'][0]['total_pnl'], '9.25', 16) === 0,
    'Contract and prediction amounts merge by date and source');
$merchant = $store->search(['start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'source_type' => 'platform'], '1');
platformPnlCheck($merchant['count'] === 1 && $merchant['lists'][0]['source_id'] === '1'
    && bccomp($merchant['lists'][0]['prediction_business_pnl'], '5', 16) === 0,
    'Authenticated merchant cannot override source scope');
$tenantSearch = $store->search(['source_type' => 'tenant', 'source_keyword' => 'White label 7',
    'start_date' => '2026-10-12', 'end_date' => '2026-10-12']);
platformPnlCheck($tenantSearch['count'] === 1 && $tenantSearch['lists'][0]['source_id'] === '7',
    'Platform source search narrows white-label rows');
$paged = $store->search(['source_type' => 'merchant', 'order_by' => 'total_pnl', 'order_dir' => 'desc',
    'page_size' => 1, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12']);
platformPnlCheck($paged['count'] === 2 && count($paged['lists']) === 1 && $paged['lists'][0]['source_id'] === '1',
    'Sort and pagination operate after contract/prediction merge');
$prediction->publish('2026-10-13', [
    ['user_id' => 11, 'play_type' => 'grid', 'symbol' => 'BTCUSDT', 'outcome' => 'loss',
        'order_count' => 1, 'stake_amount' => '2', 'return_amount' => '0'],
]);
$predictionOnly = $store->search(['start_date' => '2026-10-13', 'end_date' => '2026-10-13']);
platformPnlCheck($predictionOnly['count'] === 1 && $predictionOnly['lists'][0]['stat_status'] === 2,
    'Prediction-only date is visible and marked incomplete until contract report arrives');
$store->replace('2026-10-13', $aggregator->build('2026-10-13', [], $directory, true));
$monthly = $store->search(['period' => 'month', 'start_date' => '2026-10-12', 'end_date' => '2026-10-13'])['lists'][0];
platformPnlCheck($monthly['stat_date'] === '2026-10' && bccomp($monthly['total_pnl'], '11.25', 16) === 0,
    'Month sums daily amounts after combining both products');
$buckets = new class extends AgentFuturesFactRedisRepository {
    public array $bucket = [];
    public function __construct() {}
    public function getHour(CarbonImmutable $hourStart): ?array { return $this->bucket ?: null; }
};
$buckets->bucket = [
    'status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
    'agent_ids' => [0, 1, 2], 'window_start' => '2026-10-11 00:00:00', 'window_end' => '2026-10-11 01:00:00',
    'rows' => $facts,
];
(new PlatformPnlReportPublisher($buckets, new SourceDirectory($db), $store))->publish('2026-10-11', 1, true, [0, 1, 2]);
platformPnlCheck($store->search(['start_date' => '2026-10-11', 'end_date' => '2026-10-11'])['count'] === 1,
    'Publisher reads validated hourly fact buckets');
$buckets->bucket['agent_ids'] = [1, 2];
try {
    (new PlatformPnlReportPublisher($buckets, new SourceDirectory($db), $store))->publish('2026-10-11', 1, true, [0, 1, 2]);
    throw new RuntimeException('Accepted mismatched source scope');
} catch (RuntimeException $e) {
    platformPnlCheck(str_contains($e->getMessage(), '小时桶'), 'Publisher rejects mismatched source scope');
}
$schema->drop(PredictionUserPnlSchema::TABLE);
$withoutPredictionTable = $store->search(['start_date' => '2026-10-12', 'end_date' => '2026-10-12']);
platformPnlCheck($withoutPredictionTable['count'] === 1 && $withoutPredictionTable['lists'][0]['stat_status'] === 2,
    'Rolling deploy without prediction table retains contract amounts but marks them incomplete');
echo "Shared platform PnL report: source scope, formulas, prediction join, month, incomplete status passed.\n";
