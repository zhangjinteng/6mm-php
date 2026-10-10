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
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesFactRedisRepository;
use SixMm\Shared\FuturesReports\Legacy\AgentFuturesHourlyFactService;
use SixMm\Shared\FuturesReports\Legacy\Models\Agent;
use SixMm\Shared\FuturesReports\Legacy\FuturesDailySourceProvider;

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
$schema->create('users', function (Blueprint $t): void { $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable(); });
$schema->create($control . 'wl_sites', function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create($control . 'wl_tenants', function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
foreach ([1 => 100, 2 => 101] as $id => $site) $connection->table('agents')->insert(['id' => $id, 'pid' => 0, 'agent_code' => 'MER' . $id, 'agent_name' => 'Merchant' . $id, 'user_name' => 'merchant', 'home_site_id' => $site]);
$connection->table($control . 'wl_sites')->insert([['site_id' => 100, 'tenant_id' => 1], ['site_id' => 101, 'tenant_id' => 1]]);
$connection->table($control . 'wl_tenants')->insert(['tenant_id' => 1, 'tenant_name' => 'White label']);
$connection->table('users')->insert([['user_id' => 9007199254740993, 'home_site_id' => 100], ['user_id' => 4, 'home_site_id' => 101]]);
$directory = (new SourceDirectory($connection))->load(['9007199254740993', '4']);
$base = ['agent_id' => 1, 'user_id' => '9007199254740993', 'symbol' => 'BTCUSDT', 'order_count' => 1, 'order_amount' => '20', 'cancel_order_count' => 0, 'cancel_amount' => '0', 'fill_count' => 1, 'filled_order_ids' => ['10001'], 'execution_details' => [['9007199254740994', '10.1234567890123456']]];
$other = array_merge($base, ['agent_id' => 2, 'user_id' => '4', 'filled_order_ids' => ['10002']]);
$aggregator = new TradeReportAggregator();
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
$unmapped = $aggregator->build('2026-10-08', [array_merge($base, ['agent_id' => 0, 'user_id' => '777'])], $directory, true);
checkTrade(sourceTrade($unmapped, 'platform', '0')['status'] === 2, 'Missing site retains totals and partial status');
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
    checkTrade(Agent::query()->count() === 2, 'Legacy models remain in public when search_path is control_plane');
    checkTrade((new SourceDirectory($connection))->load(['4'])['users']['4'] === '101', 'Directory qualifies both schemas');
    checkTrade($store->search(['start_date' => '2026-10-09', 'end_date' => '2026-10-09'], '1')['count'] === 1, 'Shared report query ignores search_path');
    foreach ([['public'], ['control_plane'], ['control_plane', 'public']] as $configuredSchemas) {
        // Reuse the fixture's PDO/transaction; a second physical connection cannot
        // see the uncommitted test schema and would not test this Laravel 8 bug.
        $configured = new Illuminate\Database\PostgresConnection($connection->getPdo(), 'futures_report_test', '', array_merge($connection->getConfig(), ['schema' => $configuredSchemas]));
        TradeReportSchema::create($configured);
        $resolved = (new SourceDirectory($configured))->load(['4']);
        checkTrade(count($resolved['agents']) === 2 && $resolved['agents']['1']['source_code'] === 'MER1', 'Configured schema list must not hide public merchant fields');
        checkTrade($configured->table('public.' . TradeReportSchema::TABLE)->count() > 0, 'Qualified reports support public-only and control_plane-only configuration');
    }
}
$connection->rollBack();
echo "Futures reports: schema, scopes, decimals, daily/monthly deduplication and atomic replacement passed.\n";
