<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use SixMm\Shared\FuturesReports as Futures;
use SixMm\Shared\FuturesReports\Legacy\{AgentFuturesFactRedisRepository, AgentFuturesHourlyFactService, FuturesDailySourceProvider};
use SixMm\Shared\PlatformReports\{TradeSummarySchema, TradeSummaryStore, TradeSummaryPublisher};
use SixMm\Shared\PredictionReports\{PredictionUserPnlSchema, PredictionUserPnlStore, PredictionUserPnlPublisher, PredictionUserPnlAggregator};

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = 0;
function scopeCheck(bool $okay, string $message): void
{
    global $checks;
    ++$checks;
    if (!$okay) throw new RuntimeException($message);
}

// Isolated publication tests: no application database, real Redis or RPC writes.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id');
    $t->string('agent_code'); $t->string('agent_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id'); $t->bigInteger('home_site_id')->nullable();
    $t->bigInteger('agent_id')->nullable(); $t->integer('user_type');
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$db->table('agents')->insert([
    ['id' => 1, 'pid' => 0, 'home_site_id' => 101, 'agent_code' => 'PARENT', 'agent_name' => 'Parent', 'deleted_at' => null],
    ['id' => 5, 'pid' => 1, 'home_site_id' => 999, 'agent_code' => 'CHILD', 'agent_name' => 'Child', 'deleted_at' => '2026-10-01 00:00:00'],
]);
$db->table('wl_sites')->insert([['site_id' => 101, 'tenant_id' => 10], ['site_id' => 202, 'tenant_id' => 20]]);
$db->table('wl_tenants')->insert([['tenant_id' => 10, 'tenant_name' => 'White A'], ['tenant_id' => 20, 'tenant_name' => 'White B']]);
// Users 1..5 qualify; users 6..8 have invalid origins; user 9 is absent.
foreach ([[1, 101, 5], [2, 101, 0], [3, 202, 5], [4, 202, 77], [5, 202, null],
    [6, 0, 5], [7, null, 5], [8, -1, 5]] as [$id, $site, $agent]) {
    $db->table('users')->insert(['user_id' => $id, 'home_site_id' => $site, 'agent_id' => $agent, 'user_type' => 0]);
}

$reportClasses = ['Trade', 'Pnl', 'Fee', 'FundingFee', 'Risk', 'Position', 'UserNetPnl', 'PlatformPnl'];
foreach ($reportClasses as $name) {
    $class = 'SixMm\\Shared\\FuturesReports\\' . $name . 'ReportSchema';
    $class::create($db);
}
PredictionUserPnlSchema::create($db);
TradeSummarySchema::create($db);
$directoryService = new Futures\SourceDirectory($db);
$buckets = new class extends AgentFuturesFactRedisRepository {
    public array $rows = [];
    public function __construct() {}
    public function getHour(CarbonImmutable $hourStart): ?array
    {
        return ['status' => 'completed', 'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
            'agent_ids' => [0, 1], 'window_start' => $hourStart->format('Y-m-d H:i:s'),
            'window_end' => $hourStart->addHour()->format('Y-m-d H:i:s'),
            'rows' => $this->rows, 'user_pnl_rows' => $this->rows];
    }
};

$publishers = [
    new Futures\TradeReportPublisher($buckets, $directoryService, new Futures\TradeReportStore($db)),
    new Futures\FeeReportPublisher($buckets, $directoryService, new Futures\FeeReportStore($db)),
    new Futures\FundingFeeReportPublisher($buckets, $directoryService, new Futures\FundingFeeReportStore($db)),
    new Futures\RiskReportPublisher($buckets, $directoryService, new Futures\RiskReportStore($db)),
    new Futures\UserNetPnlReportPublisher($buckets, $directoryService, new Futures\UserNetPnlReportStore($db)),
    new Futures\PlatformPnlReportPublisher($buckets, $directoryService, new Futures\PlatformPnlReportStore($db)),
];
$pnlPublisher = new Futures\PnlReportPublisher($buckets, $directoryService, $db, new Futures\PnlReportStore($db));
$positionPublisher = new Futures\PositionReportPublisher($directoryService, new Futures\PositionReportStore($db));
$predictionPublisher = new PredictionUserPnlPublisher($db, null, new PredictionUserPnlStore($db));
$summaryPublisher = new TradeSummaryPublisher($db, new TradeSummaryStore($db), null);

// A 100 U fact counts once in each applicable layer, not once per parent merchant.
// The platform includes two direct users; it is neither the merchant subtotal nor
// the sum of all merchant + tenant rows.
$expectedUsers = ['merchant:1' => 0, 'merchant:5' => 2, 'merchant:77' => 1,
    'tenant:10' => 2, 'tenant:20' => 3, 'platform:0' => 5];
$reports = [
    [Futures\TradeReportSchema::TABLE, 'trade_amount', 'trading_user_count', 1],
    [Futures\PnlReportSchema::TABLE, 'net_pnl', 'trading_user_count', 1],
    [Futures\FeeReportSchema::TABLE, 'total_handling_fee', 'fee_user_count', 1],
    [Futures\FundingFeeReportSchema::TABLE, 'funding_fee_net_income', 'funding_settlement_user_count', 1],
    [Futures\RiskReportSchema::TABLE, 'liquidation_trade_amount', 'liquidation_user_count', 1],
    [Futures\PositionReportSchema::TABLE, 'closing_position_amount', 'position_user_count', 1],
    [Futures\UserNetPnlReportSchema::TABLE, 'realized_pnl', 'settled_user_count', 1],
    [Futures\PlatformPnlReportSchema::TABLE, 'contract_trade_pnl', null, -1],
    [PredictionUserPnlSchema::TABLE, 'user_net_pnl', 'trading_user_count', 1],
    [TradeSummarySchema::TABLE, 'prediction_valid_stake_amount', 'total_trading_user_count', 1],
];
$date = '2026-10-09';
CarbonImmutable::setTestNow('2026-10-10 12:00:00 UTC');
foreach ([0, 1, 2, 3, 99] as $userType) {
    $db->table('users')->update(['user_type' => $userType]);
    $directory = $directoryService->load(range(1, 9));
    scopeCheck(count($directory['users']) === 5, "type {$userType}: exclude zero/NULL/negative/missing user sites");
    scopeCheck($directory['users'][5]['agent_id'] === '0', "type {$userType}: NULL merchant is a direct user");
    scopeCheck(isset($directory['agents'][5], $directory['agents'][77]),
        "type {$userType}: child/deleted merchant and missing metadata still follow users.agent_id");
    $buckets->rows = [];
    $predictionFacts = [];
    foreach (range(1, 9) as $id) {
        $buckets->rows[] = [
            'user_id' => (string) $id, 'user_type' => $userType, 'agent_id' => 999,
            'symbol' => 'BTCUSDT', 'settle_asset' => 'USDT',
            'order_count' => 1, 'order_amount' => '100', 'fill_count' => 1,
            'filled_order_ids' => [(string) $id], 'execution_details' => [[(string) $id, '100']],
            'realized_pnl' => '100', 'trading_fee' => '0',
            'fee_count' => 1, 'maker_trade_amount' => '100', 'maker_handling_fee' => '100',
            'funding_settlement_count' => 1, 'funding_settlement_position_amount' => '100',
            'funding_fee' => '100', 'funding_fee_income' => '100', 'funding_fee_expense' => '0',
            'liquidation_position_ids' => [(string) $id], 'liquidation_position_amounts' => [$id => '100'],
            'liquidation_trade_amount' => '100',
            'long_position_amount' => '100', 'closing_position_amount' => '100', 'occupied_margin' => '10',
            'close_order_count' => 1, 'close_order_ids' => [(string) $id], 'close_amount' => '100',
        ];
        $predictionFacts[] = ['user_id' => (string) $id, 'play_type' => 'up_down', 'symbol' => 'BTCUSDT',
            'outcome' => 'win', 'order_count' => 1, 'stake_amount' => '100', 'return_amount' => '200'];
    }
    foreach ($publishers as $publisher) $publisher->publish($date, 1, true, [0, 1]);
    $pnlPublisher->publish($date, 1, [0, 1]);
    $positionPublisher->publish($date, ['status' => 'completed',
        'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
        'snapshot_at' => '2026-10-10 00:00:00', 'rows' => $buckets->rows], true);
    $predictionPublisher->publish($date, $predictionFacts);
    $summaryPublisher->publish($date, $predictionFacts);

    foreach ($reports as [$table, $amount, $count, $sign]) {
        $query = $db->table($table)->where('stat_date', $date);
        if ($table === Futures\FundingFeeReportSchema::TABLE) {
            // Funding reports persist a distinct-user set, not a redundant count.
            $query->select('*')->selectRaw('json_array_length(user_ids) AS funding_settlement_user_count');
        }
        if ($table === PredictionUserPnlSchema::TABLE) $query->where('play_type', 'all')->where('symbol', '*');
        elseif ($schema->hasColumn($table, 'symbol')) $query->where('symbol', '');
        $rows = [];
        foreach ($query->get() as $row) $rows[$row->source_type . ':' . $row->source_id] = (array) $row;
        scopeCheck(count($rows) === count($expectedUsers), "type {$userType}, {$table}: exactly six sources, no historical merchant 999");
        foreach ($expectedUsers as $source => $users) {
            $label = "type {$userType}, {$table}, {$source}";
            scopeCheck(isset($rows[$source]), "{$label}: source is published");
            scopeCheck(bccomp((string) $rows[$source][$amount], (string) ($sign * 100 * $users), 16) === 0,
                "{$label}: {$amount} must equal " . ($sign * 100 * $users));
            if ($count !== null) scopeCheck((int) $rows[$source][$count] === $users, "{$label}: distinct users must equal {$users}");
            scopeCheck((int) $rows[$source]['status'] === 1, "{$label}: excluded origins/cross-site merchants do not make valid data incomplete");
            if ($source === 'platform:0') scopeCheck($rows[$source]['source_name'] === '6MM', "{$label}: platform is named 6MM");
            if ($table === TradeSummarySchema::TABLE) {
                scopeCheck(bccomp((string) $rows[$source]['contract_trade_amount'], (string) (100 * $users), 16) === 0,
                    "{$label}: contract and prediction cohorts agree without double-counting users");
            }
        }
    }

    // Compatibility parameters must not re-enable old parent roll-up or robot exclusions.
    $legacyParams = (new PredictionUserPnlAggregator())->build($date, $predictionFacts, $directory,
        [5 => 1, 77 => 1], array_fill_keys(range(1, 9), true), true);
    foreach ($legacyParams as $row) {
        if ($row['source_type'] === 'platform' && $row['play_type'] === 'all' && $row['symbol'] === '*') {
            scopeCheck($row['trading_user_count'] === 5 && bccomp($row['user_net_pnl'], '500', 16) === 0,
                "type {$userType}: old robot/root arguments do not change the report cohort");
        }
    }
}
CarbonImmutable::setTestNow();

// Inspect generated collection queries without opening a ClickHouse connection.
DB::swap($db);
$schema->create('symbol_config', static function (Blueprint $t): void { $t->string('symbol'); $t->string('quote_asset'); });
$db->table('symbol_config')->insert(['symbol' => 'BTCUSDT', 'quote_asset' => 'USDT']);
$queries = [];
$provider = new FuturesDailySourceProvider(static function (string $sql) use (&$queries): array { $queries[] = $sql; return []; }, 'freedex_history');
foreach (['orderRows', 'fillRows', 'feeRows', 'cancelRows', 'userPnlSettlementRows', 'executionSummaryRows',
    'commissionRows', 'riskRows', 'fundingSettlementPositionRows', 'liquidationRows', 'bankruptcyRows'] as $method) {
    $queries = [];
    $collect = static fn () => $provider->$method([0, 1], CarbonImmutable::parse($date, 'UTC'), CarbonImmutable::parse($date, 'UTC')->addHour());
    if (in_array($method, ['commissionRows', 'riskRows'], true)) {
        // These two collectors use the relational DB, not the injected ClickHouse executor.
        foreach ($db->pretend($collect) as $query) $queries[] = $query['query'] . ' ' . json_encode($query['bindings'], JSON_THROW_ON_ERROR);
    } else {
        $collect();
    }
    scopeCheck($queries !== [], "{$method}: query generation is exercised");
    foreach ($queries as $sql) {
        scopeCheck(!preg_match('/\b(?:agent_id|user_type)\s*(?:IN\s*\(|=)/i', str_replace(['"', '`'], '', $sql)),
            "{$method}: collection must not filter historical agents or user types");
        scopeCheck(str_contains($sql, '2026-10-09 00:00:00') && str_contains($sql, '2026-10-09 01:00:00'),
            "{$method}: removing ownership predicates must retain the bounded UTC window");
    }
}
scopeCheck(Futures\ReportUserScope::executionRows([array_merge($buckets->rows[0], ['user_type' => 0])])[0]['fill_count'] === 1,
    'Type zero is not duplicated when it also represents the legacy all-types execution bucket');
echo "PASS {$checks} unified report source-scope checks (five user types, ten publishers and eleven collectors)\n";
