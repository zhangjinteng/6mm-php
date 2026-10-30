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
use SixMm\Shared\FuturesReports\PnlReportAggregator;
use SixMm\Shared\FuturesReports\PnlReportPublisher;
use SixMm\Shared\FuturesReports\PnlReportSchema;
use SixMm\Shared\FuturesReports\PnlReportStore;
use SixMm\Shared\FuturesReports\FundingFeeReportAggregator;
use SixMm\Shared\FuturesReports\FundingFeeReportSchema;
use SixMm\Shared\FuturesReports\FundingFeeReportStore;
use SixMm\Shared\FuturesReports\FundingFeeReportPublisher;
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
PnlReportSchema::create($connection);
FundingFeeReportSchema::create($connection);
FundingFeeReportSchema::create($connection);
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
$fundingAggregator = new FundingFeeReportAggregator();
$fundingFact = [
    'agent_id' => 1, 'user_type' => 2, 'user_id' => '9007199254740993', 'symbol' => 'BTCUSDT',
    'settle_asset' => 'USDT', 'funding_settlement_count' => 2,
    'funding_settlement_position_amount' => '100.0000000000000001',
    'funding_fee_income' => '1.2000000000000001', 'funding_fee_expense' => '0.2', 'funding_fee' => '1.0000000000000001',
];
$fundingRows = $fundingAggregator->build('2026-10-09', [
    $fundingFact, array_merge($fundingFact, ['funding_settlement_count' => 1, 'funding_settlement_position_amount' => '50', 'funding_fee_income' => '0', 'funding_fee_expense' => '0.4', 'funding_fee' => '-0.4']),
    array_merge($fundingFact, ['user_type' => 1, 'funding_fee_income' => '1000', 'funding_fee_expense' => '0', 'funding_fee' => '1000']),
], $directory, true);
$fundingMerchant = sourceTrade($fundingRows, 'merchant', '1');
checkTrade($fundingMerchant['funding_settlement_user_count'] === 1 && $fundingMerchant['funding_settlement_count'] === 5,
    'Funding users deduplicate within a day while settlements include all user types');
checkTrade($fundingMerchant['funding_fee_net_income'] === '1000.6000000000000001', 'Funding decimals remain exact across all user types');
$fundingStore = new FundingFeeReportStore($connection);
$fundingStore->replace('2026-10-09', $fundingRows);
$fundingStore->replace('2026-10-10', $fundingAggregator->build('2026-10-10', [$fundingFact], $directory, false));
$fundingMonthly = $fundingStore->search(['period' => 'month', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10'], '1')['lists'][0];
checkTrade($fundingMonthly['funding_settlement_user_count'] === 1 && $fundingMonthly['funding_settlement_count'] === 7
    && $fundingMonthly['stat_status'] === 0, 'Funding month deduplicates users and preserves processing status');
$fundingZero = $fundingStore->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'symbol' => 'ETHUSDT'], '1')['lists'][0];
checkTrade($fundingZero['funding_settlement_user_count'] === 0 && $fundingZero['funding_fee_net_income'] === '0', 'Missing contract retains zero-day skeleton');
checkTrade($fundingStore->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'source_type' => 'tenant', 'source_keyword' => 'White label'])['count'] === 2,
    'Source search filters funding totals before pagination');
checkTrade($fundingStore->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'source_type' => 'platform'])['count'] === 2,
    'Platform scope exists independently from merchants');
try { $fundingStore->search(['order_by' => 'user_ids']); throw new RuntimeException('Accepted private funding sort'); }
catch (InvalidArgumentException $e) {}
$fundingBuckets = new class extends AgentFuturesFactRedisRepository {
    public array $bucket = [];
    public function __construct() {}
    public function getHour(CarbonImmutable $hourStart): ?array { return $this->bucket ?: null; }
};
$fundingBuckets->bucket = [
    'status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
    'agent_ids' => [0, 1, 2], 'window_start' => '2026-10-09 00:00:00', 'window_end' => '2026-10-09 01:00:00',
    'rows' => [$fundingFact],
];
(new FundingFeeReportPublisher($fundingBuckets, new SourceDirectory($connection), $fundingStore))
    ->publish('2026-10-09', 1, true, [0, 1, 2]);
checkTrade($fundingStore->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09'], '1')['lists'][0]['funding_settlement_count'] === 2,
    'Funding publisher replaces the daily snapshot');
$fundingBuckets->bucket['agent_ids'] = [1, 2];
try { (new FundingFeeReportPublisher($fundingBuckets, new SourceDirectory($connection), $fundingStore))->publish('2026-10-09', 1, true, [0, 1, 2]); throw new RuntimeException('Accepted mismatched funding bucket'); }
catch (RuntimeException $e) { checkTrade(str_contains($e->getMessage(), '小时桶'), 'Mismatched bucket cannot overwrite funding report'); }
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
checkTrade(sourceTrade($crossSite, 'merchant', '1')['order_count'] === 1 && sourceTrade($crossSite, 'tenant', '1')['status'] === 1,
    'Merchant attribution uses users.agent_id independently of users.home_site_id');
$crossMerchant = $aggregator->build('2026-10-09', [array_merge($base, ['user_id' => '4'])], $scopedDirectory, true);
checkTrade(sourceTrade($crossMerchant, 'merchant', '1')['order_count'] === 0
    && sourceTrade($crossMerchant, 'merchant', '2')['order_count'] === 1, 'User ownership takes precedence over the historical fact merchant');
$connection->table('agents')->insert(['id' => 3, 'pid' => 0, 'agent_code' => 'M3', 'agent_name' => 'Other', 'user_name' => 'other', 'home_site_id' => 100]);
$connection->table('agents')->insert(['id' => 11, 'pid' => 1, 'agent_code' => 'sub', 'agent_name' => 'Sub', 'user_name' => 'sub', 'home_site_id' => 100]);
$connection->table('agents')->insert(['id' => 12, 'pid' => 1, 'agent_code' => 'bad', 'agent_name' => 'Bad', 'user_name' => 'bad', 'home_site_id' => 101]);
checkTrade(MerchantReportScope::forAccount($connection, '11')->merchantId === '1', 'Subaccounts inherit the authenticated root merchant');
checkTrade(MerchantReportScope::forAccount($connection, '2')->siteId === '101', 'Shared merchant backend resolves different white-label sites dynamically');
checkTrade(MerchantReportScope::forAccount($connection, '1', '101')->merchantId === '1', 'Deployment site is not a merchant-report access prerequisite');
checkTrade(MerchantReportScope::forAccount($connection, '12')->merchantId === '1', 'Subaccount reads the authenticated parent despite different white-label metadata');
foreach ([['0', null], ['404', null]] as [$account, $site]) {
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
foreach (['day', 'month'] as $searchPeriod) {
    $searchParams = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'period' => $searchPeriod];
    $nameResult = $store->search(array_merge($searchParams, ['source_keyword' => 'CHANT1']));
    checkTrade($nameResult['count'] === ($searchPeriod === 'day' ? 2 : 1)
        && array_unique(array_column($nameResult['lists'], 'source_id')) === ['1'], 'Source names match case-insensitive partial text before aggregation');
    $idResult = $store->search(array_merge($searchParams, ['source_keyword' => '2']));
    checkTrade($idResult['count'] === ($searchPeriod === 'day' ? 2 : 1)
        && array_unique(array_column($idResult['lists'], 'source_id')) === ['2'], 'Source ID matches exactly');
    foreach (['%', '_', "' OR 1=1 --", '9223372036854775808'] as $literal) {
        checkTrade($store->search(array_merge($searchParams, ['source_keyword' => $literal]))['count'] === 0, 'Wildcards, quotes and oversized IDs do not broaden results or overflow');
    }
    $ownSearch = $store->search(array_merge($searchParams, ['source_keyword' => 'Merchant2']), '1');
    checkTrade($ownSearch['count'] === ($searchPeriod === 'day' ? 2 : 1)
        && array_unique(array_column($ownSearch['lists'], 'source_id')) === ['1'], 'Source keyword cannot override the authenticated merchant scope');
}
$namePage = $store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'source_keyword' => 'Merchant', 'page_size' => 1, 'page_no' => 3]);
checkTrade($namePage['count'] === 4 && count($namePage['lists']) === 1 && $namePage['page'] === 3, 'Source-name filters share the total and pagination predicate');
$params = ['start_date' => '2026-10-09', 'end_date' => '2026-10-10', 'period' => 'month', 'source_type' => 'tenant', 'source_id' => '1'];
$monthly = $store->search($params)['lists'][0];
checkTrade($monthly['trading_user_count'] === 2 && $monthly['filled_order_count'] === 2 && $monthly['fill_count'] === 1, 'Month uses identity union, not daily sums');
checkTrade($monthly['stat_status'] === 0, 'Current month stays processing');
checkTrade(!isset($monthly['identities']) && is_string($monthly['source_id']), 'Do not expose raw identity sets or numeric BIGINTs');
foreach (['day', 'month'] as $period) {
    $symbolParams = array_merge($params, ['period' => $period]);
    $fullSymbol = $store->search(array_merge($symbolParams, ['symbol' => 'BTCUSDT']));
    checkTrade($fullSymbol['count'] > 0 && $fullSymbol['lists'][0]['order_count'] > 0, 'Full contract symbol retains matching report data');
    foreach (['btc', 'BTC', 'btcusdt'] as $alias) {
        $matched = $store->search(array_merge($symbolParams, ['symbol' => $alias]));
        checkTrade($matched === $fullSymbol, 'Base ticker and mixed-case contract resolve to BTCUSDT for daily and monthly reports');
    }
}
$own = $store->search(array_merge($params, ['source_id' => '2', 'source_type' => 'platform']), '1');
checkTrade($own['count'] === 1 && $own['lists'][0]['source_type'] === 'merchant' && $own['lists'][0]['source_id'] === '1', 'Server-derived merchant scope overrides hostile params');
foreach (['day', 'month'] as $period) {
    $scoped = $store->search(array_merge($params, ['period' => $period]), '1', '101');
    checkTrade($scoped['lists'][0]['source_type'] === 'merchant' && $scoped['lists'][0]['source_id'] === '1', 'Merchant reads its published rows regardless of legacy site constraint');
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
checkTrade(str_contains($sqls[0], 'execution_details') && str_contains($sqls[0], 'history_order_query FINAL')
    && str_contains($sqls[0], 'WHERE (user_id, order_id) IN'), 'SQL retains FINAL, exact trade-order keys and execution amounts');
checkTrade(!preg_match('/\b(?:agent_id|user_type)\s*(?:IN\s*\(|=)/i', $sqls[0]),
    'Raw facts must not be filtered by historical merchant or user type before resolving public user ownership');
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
        checkTrade(count($resolved['agents']) === 5 && $resolved['agents']['1']['source_code'] === 'MER1', 'Configured schema list must not hide parent or child merchant fields');
        checkTrade($configured->table('public.' . TradeReportSchema::TABLE)->count() > 0, 'Qualified reports support public-only and control_plane-only configuration');
    }
}
$literalDirectory = ['agents' => [[
    'source_type' => 'merchant', 'source_id' => '9007199254740993', 'source_code' => 'BIG', 'source_name' => '100%_!交易台',
]], 'tenants' => [], 'sites' => [], 'users' => []];
$store->replace('2026-09-01', $aggregator->build('2026-09-01', [], $literalDirectory, true));
foreach (['day', 'month'] as $period) {
    foreach (['%_!', '交易台', '9007199254740993'] as $keyword) {
        $literal = $store->search(['start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'period' => $period, 'source_keyword' => $keyword]);
        checkTrade($literal['count'] === 1 && $literal['lists'][0]['source_id'] === '9007199254740993', 'Literal wildcards, Chinese names and BIGINT IDs remain searchable');
    }
}
try { $store->search(['source_keyword' => str_repeat('字', 129)]); throw new RuntimeException('Accepted excessive source keyword'); }
catch (InvalidArgumentException $e) {}
$pnlAggregator = new PnlReportAggregator();
$pnlStore = new PnlReportStore($connection);
$pnlDayOneFacts = [array_merge($base, ['realized_pnl' => '2.0000000000000000']),
    array_merge($other, ['realized_pnl' => '-1.0000000000000000'])];
$pnlDayTwoFacts = [array_merge($base, ['realized_pnl' => '-3.0000000000000000']),
    array_merge($other, ['realized_pnl' => '2.0000000000000000'])];
$pnlDayOne = $pnlAggregator->build('2026-10-09', $pnlDayOneFacts, $directory,
    $aggregator->build('2026-10-09', $pnlDayOneFacts, $directory, true));
$pnlDayTwo = $pnlAggregator->build('2026-10-10', $pnlDayTwoFacts, $directory,
    $aggregator->build('2026-10-10', $pnlDayTwoFacts, $directory, false));
$pnlStore->replace('2026-10-09', $pnlDayOne);
$pnlStore->replace('2026-10-10', $pnlDayTwo);
$pnlDaily = $pnlStore->search(['period' => 'day', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10',
    'source_type' => 'tenant', 'source_id' => '1']);
checkTrade($pnlDaily['count'] === 2 && $pnlDaily['lists'][1]['profitable_user_count'] === 1
    && $pnlDaily['lists'][1]['losing_user_count'] === 1, 'Daily user PnL classifies after per-user aggregation');
$pnlMonthly = $pnlStore->search(['period' => 'month', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10',
    'source_type' => 'tenant', 'source_id' => '1'])['lists'][0];
checkTrade($pnlMonthly['trading_user_count'] === 2 && $pnlMonthly['profitable_user_count'] === 1
    && $pnlMonthly['losing_user_count'] === 1 && bccomp($pnlMonthly['profit_amount'], '1', 16) === 0
    && bccomp($pnlMonthly['loss_amount'], '1', 16) === 0 && bccomp($pnlMonthly['net_pnl'], '0', 16) === 0,
    'Monthly PnL reclassifies per user across dates, rather than summing daily winners');
checkTrade(!isset($pnlMonthly['user_metrics']) && $pnlMonthly['stat_status'] === 0, 'Response hides identity JSON and keeps processing state');
$pnlMerchant = $pnlStore->search(['period' => 'month', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10',
    'source_type' => 'platform', 'source_id' => '2'], '1')['lists'][0];
checkTrade($pnlMerchant['source_type'] === 'merchant' && $pnlMerchant['source_id'] === '1'
    && $pnlMerchant['profitable_user_count'] === 0, 'Authenticated merchant scope cannot be replaced by request scope');
$pnlZero = $pnlStore->search(['period' => 'day', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10',
    'source_type' => 'tenant', 'source_id' => '1', 'symbol' => 'ETHUSDT']);
checkTrade($pnlZero['count'] === 2 && bccomp($pnlZero['lists'][0]['net_pnl'], '0', 16) === 0,
    'Unknown symbol retains zero-valued date skeleton');
foreach (['btc', 'BTC', 'BTCUSDT'] as $alias) {
    $matched = $pnlStore->search(['period' => 'month', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10',
        'source_type' => 'tenant', 'source_id' => '1', 'symbol' => $alias]);
    checkTrade($matched['count'] === 1 && $matched['lists'][0]['trading_user_count'] === 2,
        'Daily symbol facts are available under base ticker and full symbol in monthly PnL');
}
$connection->enableQueryLog();
$pnlStore->search(['period' => 'day', 'start_date' => '2026-10-09', 'end_date' => '2026-10-10'], '1');
foreach ($connection->getQueryLog() as $query) checkTrade(!str_contains($query['query'], 'user_metrics'), 'Daily PnL pages must not fetch identity JSON');
$connection->disableQueryLog();
try { $pnlStore->search(['order_by' => 'user_metrics']); throw new RuntimeException('Accepted private PnL sort'); }
catch (InvalidArgumentException $e) {}
$buckets->bucket = ['status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
    'agent_ids' => [0, 1, 2], 'window_start' => '2026-10-09 00:00:00', 'window_end' => '2026-10-09 01:00:00',
    'rows' => $pnlDayOneFacts];
$pnlPublisher = new PnlReportPublisher($buckets, new SourceDirectory($connection), $connection, $pnlStore);
checkTrade($pnlPublisher->publish('2026-10-09', 1, [0, 1, 2]) > 0,
    'PnL publisher consumes validated shared hour facts and the trade-report notional');
$publishedPnl = $pnlStore->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09',
    'source_type' => 'tenant', 'source_id' => '1'])['lists'][0];
checkTrade($publishedPnl['profitable_user_count'] === 1 && $publishedPnl['losing_user_count'] === 1
    && bccomp($publishedPnl['trade_amount'], sourceTrade($store->search([
        'start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'source_type' => 'tenant', 'source_id' => '1',
    ])['lists'], 'tenant', '1')['trade_amount'], 16) === 0,
    'Published PnL reuses exactly the shared trade-report成交金额');
$buckets->bucket['agent_ids'] = [1, 2];
try { $pnlPublisher->publish('2026-10-09', 1, [0, 1, 2]); throw new RuntimeException('Accepted mismatched PnL bucket'); }
catch (RuntimeException $e) { checkTrade(str_contains($e->getMessage(), '小时桶'), 'Invalid PnL bucket cannot overwrite the report'); }
checkTrade($pnlStore->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09',
    'source_type' => 'tenant', 'source_id' => '1'])['lists'][0]['net_pnl'] === $publishedPnl['net_pnl'],
    'Failed PnL publication preserves the previous report');
$connection->rollBack();
echo "Futures reports: schema, scopes, decimals, daily/monthly deduplication and atomic replacement passed.\n";
