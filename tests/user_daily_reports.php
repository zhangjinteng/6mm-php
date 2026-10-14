<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\UserReports\UserDailyReportStore;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkUserDaily(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid'); $t->bigInteger('home_site_id')->nullable();
    $t->string('agent_code'); $t->string('agent_name'); $t->string('user_name'); $t->timestamp('deleted_at')->nullable();
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->bigInteger('site_id'); $t->bigInteger('tenant_id'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->bigInteger('tenant_id'); $t->string('tenant_name'); });
$schema->create('admin_agent_user_daily_statistics', static function (Blueprint $t): void {
    $t->bigInteger('agent_id'); $t->date('stat_date'); $t->smallInteger('user_type');
    foreach (['initial_user_count', 'new_user_count', 'transfer_in_user_count', 'transfer_out_user_count',
        'active_user_count', 'trading_user_count', 'prediction_trading_user_count', 'total_trading_user_count',
        'contract_trade_count'] as $field) $t->bigInteger($field);
    foreach (['transfer_in_amount', 'transfer_out_amount', 'contract_trade_amount', 'contract_trade_pnl',
        'contract_trading_fee_income', 'contract_liquidation_fee_income'] as $field) $t->string($field);
});
$schema->create('admin_agent_prediction_user_pnl_daily_statistics', static function (Blueprint $t): void {
    $t->bigInteger('agent_id'); $t->date('stat_date'); $t->string('play_type'); $t->string('symbol');
    $t->string('settle_asset'); $t->bigInteger('win_order_count'); $t->bigInteger('lose_order_count');
    $t->string('valid_stake_amount');
});
$db->table('agents')->insert([
    ['id' => 10, 'pid' => 0, 'home_site_id' => 100, 'agent_code' => 'A10', 'agent_name' => 'Alpha', 'user_name' => 'a'],
    ['id' => 11, 'pid' => 10, 'home_site_id' => 100, 'agent_code' => 'A11', 'agent_name' => 'Sub', 'user_name' => 's'],
    ['id' => 20, 'pid' => 0, 'home_site_id' => 101, 'agent_code' => 'B20', 'agent_name' => 'Beta', 'user_name' => 'b'],
    ['id' => 30, 'pid' => 0, 'home_site_id' => 200, 'agent_code' => 'C30', 'agent_name' => 'Gamma', 'user_name' => 'c'],
]);
$db->table('wl_sites')->insert([
    ['site_id' => 100, 'tenant_id' => 1], ['site_id' => 101, 'tenant_id' => 1], ['site_id' => 200, 'tenant_id' => 2],
]);
$db->table('wl_tenants')->insert([
    ['tenant_id' => 1, 'tenant_name' => 'White One'], ['tenant_id' => 2, 'tenant_name' => 'White Two'],
    ['tenant_id' => 3, 'tenant_name' => 'Empty White Label'],
]);
$fact = static function (int $agent, string $date, int $type, int $initial, int $fresh, int $transfer,
    string $amount, int $active, int $trading): array {
    return ['agent_id' => $agent, 'stat_date' => $date, 'user_type' => $type,
        'initial_user_count' => $initial, 'new_user_count' => $fresh,
        'transfer_in_user_count' => $transfer, 'transfer_in_amount' => $amount,
        'transfer_out_user_count' => 0, 'transfer_out_amount' => '0', 'active_user_count' => $active,
        'trading_user_count' => $trading, 'prediction_trading_user_count' => 0,
        'total_trading_user_count' => $trading, 'contract_trade_count' => 0,
        'contract_trade_amount' => '0', 'contract_trade_pnl' => '0',
        'contract_trading_fee_income' => '0', 'contract_liquidation_fee_income' => '0'];
};
$db->table('admin_agent_user_daily_statistics')->insert([
    $fact(10, '2026-10-13', 1, 100, 2, 1, '0.1000000000000001', 4, 2),
    $fact(10, '2026-10-13', 2, 50, 1, 2, '0.2000000000000001', 2, 1),
    $fact(20, '2026-10-13', 1, 40, 1, 1, '1.0000000000000000', 1, 1),
    $fact(30, '2026-10-13', 1, 20, 0, 0, '0', 0, 0),
    $fact(10, '2026-10-14', 1, 103, 1, 2, '2.5', 5, 3),
    $fact(10, '2026-10-14', 2, 51, 0, 0, '0', 1, 1),
    $fact(20, '2026-10-14', 1, 41, 0, 0, '0', 0, 0),
]);
$db->table('admin_agent_prediction_user_pnl_daily_statistics')->insert([
    ['agent_id' => 10, 'stat_date' => '2026-10-13', 'play_type' => 'all', 'symbol' => '*',
        'settle_asset' => 'USDT', 'win_order_count' => 2, 'lose_order_count' => 1, 'valid_stake_amount' => '5.5'],
    ['agent_id' => 20, 'stat_date' => '2026-10-13', 'play_type' => 'all', 'symbol' => '*',
        'settle_asset' => 'USDT', 'win_order_count' => 1, 'lose_order_count' => 0, 'valid_stake_amount' => '2'],
]);
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
$store = new UserDailyReportStore($db);
$range = ['start_date' => '2026-10-13', 'end_date' => '2026-10-14'];
$merchant = $store->search($range, '10');
checkUserDaily($merchant['count'] === 2 && $merchant['lists'][1]['initial_user_count'] === 150,
    'Authenticated merchant combines real and simulated rows without other merchants');
checkUserDaily($merchant['lists'][1]['transfer_in_amount'] === '0.3000000000000002'
    && $merchant['lists'][1]['transfer_in_conversion_rate'] === '2.00', 'Amounts remain exact and rates are recomputed');
checkUserDaily($merchant['lists'][1]['prediction_valid_order_count'] === 3
    && $merchant['lists'][1]['prediction_valid_stake_amount'] === '5.5000000000000000',
    'Prediction metrics join once per merchant/day, not once per user type');
checkUserDaily($merchant['lists'][0]['stat_status'] === 'processing'
    && $merchant['lists'][1]['stat_status'] === 'completed', 'UTC date controls status');
$tenant = $store->search($range + ['source_type' => 'tenant', 'source_id' => '1']);
checkUserDaily($tenant['count'] === 2 && $tenant['lists'][1]['initial_user_count'] === 190,
    'White-label total combines merchants across sites');
$allTenants = $store->search($range + ['source_type' => 'tenant']);
checkUserDaily($allTenants['count'] === 6, 'Every known white label has a row on each reporting date');
$emptyTenant = $store->search($range + ['source_type' => 'tenant', 'source_id' => '3']);
checkUserDaily($emptyTenant['count'] === 2
    && $emptyTenant['lists'][0]['initial_user_count'] === 0
    && $emptyTenant['lists'][0]['transfer_in_amount'] === '0.0000000000000000'
    && $emptyTenant['lists'][0]['active_rate'] === '0.00',
    'White label without merchants is listed with zero metrics');
$idleTenant = $store->search(['start_date' => '2026-10-14', 'end_date' => '2026-10-14',
    'source_type' => 'tenant', 'source_id' => '2']);
checkUserDaily($idleTenant['count'] === 1 && $idleTenant['lists'][0]['initial_user_count'] === 0,
    'White label without facts on a reporting date is listed with zero metrics');
$platform = $store->search($range + ['source_type' => 'platform']);
checkUserDaily($platform['count'] === 2 && $platform['lists'][1]['initial_user_count'] === 210,
    'Platform merchant cohort includes every valid merchant exactly once');
$month = $store->search($range + ['period' => 'month', 'source_type' => 'tenant', 'source_id' => '1']);
checkUserDaily($month['count'] === 1 && $month['lists'][0]['initial_user_count'] === 190
    && $month['lists'][0]['new_user_count'] === 5 && $month['lists'][0]['transfer_in_user_count'] === 6,
    'Monthly opening count is the first snapshot, flows sum, and rates are recalculated');
$allTenantMonths = $store->search($range + ['period' => 'month', 'source_type' => 'tenant']);
checkUserDaily($allTenantMonths['count'] === 3,
    'Monthly white-label report includes white labels without merchant facts');
checkUserDaily($store->search($range + ['source_type' => 'merchant', 'source_keyword' => 'beta'])['count'] === 2,
    'Source name search is case-insensitive and scoped before pagination');
checkUserDaily($store->search($range + ['source_type' => 'merchant', 'source_id' => '20'], '10')['count'] === 2,
    'Request source filters cannot override authenticated merchant scope');
checkUserDaily($store->search($range + ['page_size' => 1, 'page_no' => 2], '10')['lists'][0]['stat_date'] === '2026-10-13',
    'Pagination follows sorting');
foreach (['user_ids', 'drop table users'] as $sort) {
    try { $store->search($range + ['order_by' => $sort]); throw new RuntimeException('Accepted unsafe sort'); }
    catch (InvalidArgumentException $e) {}
}
CarbonImmutable::setTestNow();
echo "Shared user daily reports passed.\n";
