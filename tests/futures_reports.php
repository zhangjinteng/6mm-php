<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\FuturesReports\SchemaNames;
use SixMm\Shared\FuturesReports\SourceDirectory;
use SixMm\Shared\FuturesReports\TradeReportAggregator;
use SixMm\Shared\FuturesReports\TradeReportSchema;
use SixMm\Shared\FuturesReports\TradeReportStore;
use SixMm\Shared\FuturesReports\TradeReportPublisher;
use SixMm\Shared\FuturesReports\MerchantReportScope;
use SixMm\Shared\FuturesReports\ReportUserScope;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesFactRedisRepository;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesHourlyFactService;
use SixMm\Shared\FuturesReports\Legacy\Models\Agent;
use SixMm\Shared\FuturesReports\Legacy\FuturesDailySourceProvider;
use SixMm\Shared\FuturesReports\Legacy\LegacySummaryAccumulator;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkTrade(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function sourceTrade(array $rows, string $type, string $id): array {
    foreach ($rows as $row) if ($row['source_type'] === $type && (string) $row['source_id'] === $id && $row['symbol'] === '') return $row;
    throw new RuntimeException('Source not found');
}

$capsule = new Capsule();
$pgPort = isset($argv[1]) && preg_match('/^--pgsql=(\d+)$/D', $argv[1], $match) ? (int) $match[1] : null;
$capsule->addConnection($pgPort === null ? ['driver' => 'sqlite', 'database' => ':memory:'] : [
    'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $pgPort, 'database' => 'futures_report_test',
    'username' => 'postgres', 'password' => 'codex_test_only', 'schema' => ['public', 'control_plane'],
]);
$connection = $capsule->getConnection();
$capsule->setAsGlobal();
$capsule->bootEloquent();
$connection->beginTransaction();
if ($pgPort !== null) $connection->statement('CREATE SCHEMA control_plane');
$control = $pgPort === null ? '' : 'control_plane.';
TradeReportSchema::create($connection);
TradeReportSchema::create($connection);
$schema = $connection->getSchemaBuilder();
$schema->create('agents', function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->string('agent_code'); $t->string('agent_name');
    $t->string('user_name'); $t->bigInteger('home_site_id')->nullable(); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', function (Blueprint $t): void { $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable(); $t->bigInteger('agent_id')->default(0); });
$schema->create($control . 'wl_sites', function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create($control . 'wl_tenants', function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
foreach ([1 => 100, 2 => 101] as $id => $site) $connection->table('agents')->insert(['id' => $id, 'pid' => 0, 'agent_code' => 'MER' . $id, 'agent_name' => 'Merchant' . $id, 'user_name' => 'merchant', 'home_site_id' => $site]);
$connection->table($control . 'wl_sites')->insert([['site_id' => 100, 'tenant_id' => 1], ['site_id' => 101, 'tenant_id' => 1]]);
$connection->table($control . 'wl_tenants')->insert(['tenant_id' => 1, 'tenant_name' => 'White label']);
$connection->table('users')->insert([
    ['user_id' => 9007199254740993, 'home_site_id' => 100, 'agent_id' => 1],
    ['user_id' => 4, 'home_site_id' => 101, 'agent_id' => 2],
    ['user_id' => 5, 'home_site_id' => 100, 'agent_id' => 0],
    ['user_id' => 6, 'home_site_id' => 0, 'agent_id' => 1],
    ['user_id' => 7, 'home_site_id' => null, 'agent_id' => 1],
    ['user_id' => 8, 'home_site_id' => -1, 'agent_id' => 1],
    ['user_id' => 9, 'home_site_id' => 101, 'agent_id' => 1],
    ['user_id' => 10, 'home_site_id' => 999, 'agent_id' => 0],
]);
$directory = (new SourceDirectory($connection))->load(['9007199254740993', '4']);
$base = ['agent_id' => 1, 'user_id' => '9007199254740993', 'symbol' => 'BTCUSDT', 'order_count' => 1, 'order_amount' => '20', 'cancel_order_count' => 0, 'cancel_amount' => '0', 'fill_count' => 1, 'filled_order_ids' => ['10001'], 'execution_details' => [['9007199254740994', '10.1234567890123456']]];
$other = array_merge($base, ['agent_id' => 2, 'user_id' => '4', 'filled_order_ids' => ['10002']]);
$aggregator = new TradeReportAggregator();
$scopedDirectory = (new SourceDirectory($connection))->load(['9007199254740993', '4', '5', '6', '7', '8', '9', '10', '777']);
$scopedRows = $aggregator->build('2026-10-09', [$base,
    array_merge($base, ['agent_id' => 0, 'user_id' => '5']),
    array_merge($base, ['user_id' => '6']), array_merge($base, ['user_id' => '7']),
    array_merge($base, ['user_id' => '8']), array_merge($base, ['user_id' => '777']),
], $scopedDirectory, true);
checkTrade(sourceTrade($scopedRows, 'platform', '0')['order_count'] === 2, 'Exclude zero, null, negative and missing public user origins');
checkTrade(sourceTrade($scopedRows, 'tenant', '1')['trading_user_count'] === 2, 'White-label direct users remain in shared tenant totals');
checkTrade(sourceTrade($scopedRows, 'merchant', '1')['order_count'] === 1, 'Direct users must not enter a merchant total');
$crossSite = $aggregator->build('2026-10-09', [array_merge($base, ['user_id' => '9'])], $scopedDirectory, true);
checkTrade(sourceTrade($crossSite, 'merchant', '1')['order_count'] === 0, 'Merchant user must match the merchant site');
$crossMerchant = $aggregator->build('2026-10-09', [array_merge($base, ['user_id' => '4'])], $scopedDirectory, true);
checkTrade(sourceTrade($crossMerchant, 'merchant', '1')['order_count'] === 0, 'Do not reassign a different public merchant into the historical merchant');
$connection->table('agents')->insert(['id' => 3, 'pid' => 0, 'agent_code' => 'M3', 'agent_name' => 'Other', 'user_name' => 'other', 'home_site_id' => 100]);
$connection->table('agents')->insert(['id' => 11, 'pid' => 1, 'agent_code' => 'sub', 'agent_name' => 'Sub', 'user_name' => 'sub', 'home_site_id' => 100]);
$connection->table('agents')->insert(['id' => 12, 'pid' => 1, 'agent_code' => 'bad', 'agent_name' => 'Bad', 'user_name' => 'bad', 'home_site_id' => 101]);
checkTrade(MerchantReportScope::forAccount($connection, '11')->merchantId === '1', 'Subaccounts inherit the authenticated root merchant');
checkTrade(MerchantReportScope::forAccount($connection, '2')->siteId === '101', 'Shared merchant backend resolves different white-label sites dynamically');
foreach ([['1', '101'], ['12', null], ['404', null]] as [$account, $site]) {
    try { MerchantReportScope::forAccount($connection, $account, $site); throw new RuntimeException('Accepted invalid account/site'); }
    catch (InvalidArgumentException $e) {}
}
checkTrade($scopedDirectory['users']['9007199254740993']['agent_id'] === '1', 'Public user ownership is included in the directory');
checkTrade(ReportUserScope::executionRows([array_merge($base, ['user_type' => 1]), array_merge($base, ['user_type' => 2])])[0]['fill_count'] === 1, 'Legacy all-user execution scope deduplicates both user types');
try { ReportUserScope::executionRows([array_merge($base, ['user_type' => 1, 'execution_details' => []])]); throw new RuntimeException('Accepted unfilterable execution facts'); }
catch (InvalidArgumentException $e) {}
$compact = new LegacySummaryAccumulator();
$trade = array_merge($base, ['user_type' => 2, 'settle_asset' => 'USDT', 'trading_fee' => '1.1234567890123456']);
$settlement = ['agent_id' => 1, 'user_type' => 2, 'user_id' => '9007199254740993', 'settle_asset' => 'USDT',
    'close_order_ids' => ['10001'], 'close_amount' => '10', 'realized_pnl' => '2.5'];
$compact->merge([$settlement], [$trade]);
$compact->merge([array_merge($settlement, ['close_order_ids' => ['10001', '10002']])], [$trade]);
checkTrade($compact->executionRows()[0]['fill_count'] === 1, 'Streaming execution union deduplicates across hours');
checkTrade($compact->executionRows()[0]['trade_amount'] === '10.1234567890123456', 'Streaming execution amount is not summed twice');
checkTrade($compact->settlementRows()[0]['close_order_count'] === 2 && $compact->settlementRows()[0]['close_amount'] === '20.0000000000000000', 'Streaming settlement IDs are distinct but monetary flows are summed');
checkTrade($compact->feeRows()[0]['trading_fee'] === '2.2469135780246912' && !isset($compact->feeRows()[0]['execution_details']), 'Streaming fees retain exact decimals, not full hourly facts');
$rows = $aggregator->build('2026-10-09', [$base, $other], $directory, true);
$tenant = sourceTrade($rows, 'tenant', '1');
checkTrade($tenant['trading_user_count'] === 2 && $tenant['fill_count'] === 1 && $tenant['filled_order_count'] === 2, 'Tenant must deduplicate both sides across multiple sites');
checkTrade($tenant['trade_amount'] === '10.1234567890123456', 'Exact decimal notional is counted once');
checkTrade(sourceTrade($rows, 'merchant', '1')['trading_user_count'] === 1, 'Merchant and tenant ID collision must not merge');
checkTrade(sourceTrade($rows, 'platform', '0')['fill_count'] === 1, 'Platform scope deduplication');
$store = new TradeReportStore($connection);
$store->replace('2026-10-09', $rows);
$next = $aggregator->build('2026-10-10', [$base], $directory, false);
$store->replace('2026-10-10', $next);
$params = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'period' => 'month', 'source_type' => 'tenant', 'source_id' => '1'];
$monthly = $store->search($params)['lists'][0];
checkTrade($monthly['trading_user_count'] === 2 && $monthly['filled_order_count'] === 2 && $monthly['fill_count'] === 1, 'Month uses identity union, not daily sums');
checkTrade($monthly['stat_status'] === 0, 'Current month stays processing');
checkTrade(!isset($monthly['identities']) && is_string($monthly['source_id']), 'Do not expose raw identity sets or numeric BIGINTs');
$own = $store->search(array_merge($params, ['source_id' => '2', 'source_type' => 'platform']), '1');
checkTrade($own['count'] === 1 && $own['lists'][0]['source_type'] === 'merchant' && $own['lists'][0]['source_id'] === '1', 'Server-derived merchant scope overrides hostile params');
foreach (['day', 'month'] as $period) {
    try { $store->search(array_merge($params, ['period' => $period]), '1', '101'); throw new RuntimeException('Accepted wrong merchant site'); }
    catch (InvalidArgumentException $e) {}
}
$btc = $store->search(array_merge($params, ['symbol' => 'ETHUSDT']))['lists'][0];
checkTrade($btc['fill_count'] === 0, 'Single contract retains zero-day skeleton');
$connection->enableQueryLog();
$daily = $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'page_size' => 1, 'page_no' => 2, 'order_by' => 'trade_amount', 'order_dir' => 'desc'], '1');
checkTrade($daily['count'] === 2 && count($daily['lists']) === 1 && $daily['page'] === 2, 'Daily pagination is applied by the database');
foreach ($connection->getQueryLog() as $query) checkTrade(!str_contains($query['query'], 'identities'), 'Daily requests must not read identity JSON');
$connection->disableQueryLog();
if ($pgPort !== null) checkTrade($daily['lists'][0]['trade_amount'] === '10.1234567890123456', 'Stored PostgreSQL decimal precision is preserved');
$zeroDaily = $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'symbol' => 'ETHUSDT', 'order_by' => 'amount_fill_rate'], '1');
checkTrade($zeroDaily['count'] === 2 && $zeroDaily['lists'][0]['trade_amount'] === '0', 'Daily contract skeleton and rate sorting');
try { $store->search(['start_date' => 'bad-date'], '1'); throw new RuntimeException('Accepted invalid date'); } catch (InvalidArgumentException $e) {}
try { $store->search(['order_by' => 'identities'], '1'); throw new RuntimeException('Accepted private sort field'); } catch (InvalidArgumentException $e) {}
$buckets = new class extends AgentFuturesFactRedisRepository {
    public array $bucket = [];
    public function __construct() {}
    public function getHour(CarbonImmutable $hourStart): ?array { return $this->bucket ?: null; }
};
$buckets->bucket = ['status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
    'agent_ids' => [0, 1, 2], 'window_start' => '2026-10-09 00:00:00', 'window_end' => '2026-10-09 01:00:00', 'rows' => [$base, $other]];
$publisher = new TradeReportPublisher($buckets, new SourceDirectory($connection), $store);
$publisher->publish('2026-10-09', 1, true, [0, 1, 2]);
$buckets->bucket['agent_ids'] = [1, 2];
try { $publisher->publish('2026-10-09', 1, true, [0, 1, 2]); throw new RuntimeException('Accepted incomplete collection scope'); } catch (RuntimeException $e) { checkTrade(str_contains($e->getMessage(), '小时桶'), 'Scope mismatch rejects publication'); }
checkTrade($store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09'], '1')['lists'][0]['fill_count'] === 1, 'Failed publication retains the previous report');
$unmapped = $aggregator->build('2026-10-08', [array_merge($base, ['agent_id' => 0, 'user_id' => '10'])], $scopedDirectory, true);
checkTrade(sourceTrade($unmapped, 'platform', '0')['status'] === 2, 'Positive user origin with missing tenant directory retains platform totals as partial');
$failed = false;
try { $store->replace('2026-10-09', [$rows[0], $rows[0]]); } catch (Throwable $e) { $failed = true; }
checkTrade($failed && $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09'], '1')['count'] === 1, 'Failed replacement rolls back');
checkTrade(SchemaNames::searchPath('public,control_plane') === ['public', 'control_plane'], 'Search path must be an array for Laravel 8');
try { SchemaNames::searchPath('public; DROP SCHEMA public'); throw new RuntimeException('Accepted unsafe schema'); } catch (InvalidArgumentException $e) {}
try { $aggregator->build('2026-10-09', [array_merge($base, ['execution_details' => []])], $directory, true); throw new RuntimeException('Accepted old facts'); } catch (InvalidArgumentException $e) {}
$sqls = [];
$provider = new FuturesDailySourceProvider(function (string $sql) use (&$sqls): array { $sqls[] = $sql; return []; }, 'freedex_history');
$provider->fillRows([0, 1], CarbonImmutable::parse('2026-10-09', 'UTC'), CarbonImmutable::parse('2026-10-10', 'UTC'));
checkTrade(str_contains($sqls[0], 'execution_details') && str_contains($sqls[0], 'WHERE user_id IN') && str_contains($sqls[0], 'agent_id IN (0, 1)'), 'SQL retains FINAL, candidate users, execution amounts and platform users');
if ($pgPort !== null) {
    $connection->statement('SET LOCAL search_path TO control_plane');
    checkTrade(Agent::query()->count() === 5, 'Legacy models remain in public when search_path is control_plane');
    checkTrade((new SourceDirectory($connection))->load(['4'])['users']['4']['site_id'] === '101', 'Directory qualifies both schemas');
    checkTrade($store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09'], '1')['count'] === 1, 'Shared report query ignores search_path');
    foreach ([['public'], ['control_plane'], ['control_plane', 'public']] as $configuredSchemas) {
        // Reuse the fixture's PDO/transaction; a second physical connection cannot
        // see the uncommitted test schema and would not test this Laravel 8 bug.
        $configured = new Illuminate\Database\PostgresConnection($connection->getPdo(), 'futures_report_test', '', array_merge($connection->getConfig(), ['schema' => $configuredSchemas]));
        TradeReportSchema::create($configured);
        $resolved = (new SourceDirectory($configured))->load(['4']);
        checkTrade(count($resolved['agents']) === 3 && $resolved['agents']['1']['source_code'] === 'MER1', 'Configured schema list must not hide public merchant fields');
        checkTrade($configured->table('public.' . TradeReportSchema::TABLE)->count() > 0, 'Qualified reports support public-only and control_plane-only configuration');
    }
}
$connection->rollBack();
echo "Futures reports: schema, scopes, decimals, daily/monthly deduplication and atomic replacement passed.\n";
