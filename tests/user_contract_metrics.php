<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\UserContracts\UserContractMetricsService;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $db->getConnection();
$schema = $connection->getSchemaBuilder();
$schema->create('admin_agent_futures_user_symbol_daily', static function (Blueprint $table): void {
    $table->integer('agent_id');
    $table->integer('user_id');
    $table->string('stat_date');
    $table->string('settle_asset');
    $table->decimal('trading_fee', 20, 8);
});
$schema->create('user_daily_balance', static function (Blueprint $table): void {
    $table->integer('user_id');
    $table->string('trade_date');
    $table->decimal('realized_pnl_cumulative', 20, 8);
});
$connection->table('admin_agent_futures_user_symbol_daily')->insert([
    ['agent_id' => 7, 'user_id' => 11, 'stat_date' => '2026-09-01', 'settle_asset' => 'USDT', 'trading_fee' => '1.25000000'],
    ['agent_id' => 7, 'user_id' => 11, 'stat_date' => '2026-09-28', 'settle_asset' => 'USDT', 'trading_fee' => '2.50000000'],
    ['agent_id' => 8, 'user_id' => 11, 'stat_date' => '2026-09-28', 'settle_asset' => 'USDT', 'trading_fee' => '99.00000000'],
    ['agent_id' => 7, 'user_id' => 11, 'stat_date' => '2026-09-28', 'settle_asset' => 'BTC', 'trading_fee' => '99.00000000'],
    ['agent_id' => 7, 'user_id' => 11, 'stat_date' => '2026-08-29', 'settle_asset' => 'USDT', 'trading_fee' => '99.00000000'],
]);
$connection->table('user_daily_balance')->insert([
    ['user_id' => 11, 'trade_date' => '2026-09-01', 'realized_pnl_cumulative' => '8.00000000'],
    ['user_id' => 11, 'trade_date' => '2026-09-28', 'realized_pnl_cumulative' => '9.50000000'],
]);

$service = new UserContractMetricsService($connection);
$asOf = new DateTimeImmutable('2026-09-28 12:00:00', new DateTimeZone('UTC'));
$scoped = $service->forUsers([11], [7], $asOf);
if ((float) $scoped[11]['fee_30d'] !== 3.75 || (float) $scoped[11]['pnl_30d'] !== 9.5) {
    throw new RuntimeException('Contract metrics must honor agent scope, USDT and latest PnL.');
}
$all = $service->handlingFee30dMap([11], null, $asOf);
if ((float) $all[11] !== 102.75) {
    throw new RuntimeException('Platform contract metrics must include all agent scopes.');
}
echo "User contract metrics passed.\n";
