<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\DataScope\AgentIdsScope;
use SixMm\Shared\DataScope\AllUsersScope;
use SixMm\Shared\FundingOverview\FundingOverviewListQuery;
use SixMm\Shared\FundingOverview\FundingOverviewListService;

require dirname(__DIR__) . '/vendor/autoload.php';

$database = new Capsule();
foreach (['main', 'asset'] as $name) $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], $name);
$main = $database->getConnection('main');
$asset = $database->getConnection('asset');
$main->getSchemaBuilder()->create('users', static function (Blueprint $t): void {
    $t->bigInteger('user_id')->primary(); $t->bigInteger('public_user_id')->nullable(); $t->integer('agent_id');
    $t->string('username'); $t->string('nick_name')->nullable(); $t->timestamp('deleted_at')->nullable();
});
$main->getSchemaBuilder()->create('agents', static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('pid')->default(0); $t->timestamp('deleted_at')->nullable();
});
$main->getSchemaBuilder()->create('user_assets', static function (Blueprint $t): void {
    $t->increments('ua_id'); $t->bigInteger('user_id'); $t->string('asset_type');
    $t->string('wallet_balance'); $t->string('frozen_balance'); $t->timestamp('updated_at')->nullable(); $t->softDeletes();
});
$asset->getSchemaBuilder()->create('funding_accounts', static function (Blueprint $t): void {
    $t->integer('account_id')->primary(); $t->bigInteger('user_id'); $t->integer('agent_id'); $t->string('status');
});
$asset->getSchemaBuilder()->create('funding_assets', static function (Blueprint $t): void {
    $t->integer('asset_id')->primary(); $t->string('asset_code'); $t->integer('internal_scale'); $t->string('status');
});
$asset->getSchemaBuilder()->create('funding_balances', static function (Blueprint $t): void {
    $t->increments('balance_id'); $t->integer('account_id'); $t->integer('asset_id');
    $t->string('available_amount'); $t->string('held_amount'); $t->timestamp('updated_at')->nullable();
});
$main->table('agents')->insert([['id' => 16, 'pid' => 0], ['id' => 17, 'pid' => 16], ['id' => 99, 'pid' => 0]]);
foreach ([101 => [16, 'alice'], 102 => [17, 'bob'], 103 => [99, 'outsider'], 104 => [0, 'direct'], 105 => [16, 'empty'], 106 => [16, 'literal_%']] as $id => [$agent, $name]) {
    $main->table('users')->insert(['user_id' => $id, 'public_user_id' => 900000000 + $id, 'agent_id' => $agent, 'username' => $name]);
}
$main->table('users')->insert(['user_id' => 107, 'agent_id' => 16, 'username' => 'deleted', 'deleted_at' => '2026-10-01']);
foreach ([101 => '20.0000000000000001', 102 => '50', 103 => '999', 104 => '3', 107 => '99999'] as $id => $amount) {
    $main->table('user_assets')->insert(['user_id' => $id, 'asset_type' => 'USDT', 'wallet_balance' => $amount,
        'frozen_balance' => '4', 'updated_at' => '2026-10-15 02:00:00']);
}
$main->table('user_assets')->insert(['user_id' => 101, 'asset_type' => 'BTC', 'wallet_balance' => '999', 'frozen_balance' => '999']);
$asset->table('funding_assets')->insert(['asset_id' => 1, 'asset_code' => 'USDT', 'internal_scale' => 8, 'status' => 'ENABLED']);
foreach ([[1, 101, 16, '1000000000', '200000000'], [2, 102, 16, '100000000', '0'],
    [3, 103, 99, '800000000', '0'], [4, 104, 0, '500000000', '0'],
    [5, 101, 99, '99900000000', '0'], [6, 101, 16, '99900000000', '0']] as [$account, $user, $agent, $available, $held]) {
    $asset->table('funding_accounts')->insert(['account_id' => $account, 'user_id' => $user, 'agent_id' => $agent,
        'status' => $account === 6 ? 'DISABLED' : 'ACTIVE']);
    $asset->table('funding_balances')->insert(['account_id' => $account, 'asset_id' => 1,
        'available_amount' => $available, 'held_amount' => $held, 'updated_at' => '2026-10-15 01:00:00']);
}
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$service = new FundingOverviewListService($asset, $main, 'Asia/Shanghai');
$agent = $service->search(new FundingOverviewListQuery(), new AgentIdsScope([16, 17]), 16);
$check($agent->total() === 4, 'Agent must retain scoped users even without wallets and exclude deleted/foreign users.');
$byId = array_column($agent->items(), null, 'id');
$check($byId[101]['funding_balance'] === '12.00000000', 'Funding includes available and held, excludes foreign/inactive wallets.');
$check($byId[101]['total_assets'] === '32.0000000000000001', 'Balance addition must not lose decimal precision.');
$check($byId[101]['contract_occupied'] === '4', 'Contract occupation remains a separate, non-additive column.');
$check($byId[101]['last_changed_at'] === '2026-10-15 10:00:00', 'Display timestamps use the host timezone.');
$check($byId[105]['total_assets'] === '0', 'Wallet-less users must remain zero rows.');
$check(!array_key_exists('_last_changed_at', $byId[101]), 'Private sort fields must not leave the service.');
$all = $service->search(new FundingOverviewListQuery(), new AllUsersScope());
$byId = array_column($all->items(), null, 'id');
$check($all->total() === 6 && $byId[104]['total_assets'] === '8.00000000', 'Platform includes direct users.');
$check($byId[102]['funding_balance'] === '1.00000000', 'Platform resolves a child-agent user to the main-agent funding wallet.');
$check($byId[101]['funding_balance'] === '12.00000000', 'Platform does not add a different tenant wallet to the same user.');
$page = $service->search(new FundingOverviewListQuery(pageSize: 1, orderBy: 'total_assets'), new AllUsersScope());
$check($page->total() === 6 && $page->items()[0]['id'] === 103, 'Totals are sorted across the full result before pagination.');
$uid = $service->search(new FundingOverviewListQuery(keyword: '900000102'), new AgentIdsScope([16, 17]), 16);
$check($uid->total() === 1 && $uid->items()[0]['username'] === 'bob', 'Public UID searches work.');
$name = $service->search(new FundingOverviewListQuery(keyword: 'ALICE'), new AllUsersScope());
$check($name->total() === 1, 'Username search is case-insensitive.');
$literal = $service->search(new FundingOverviewListQuery(keyword: '_%'), new AllUsersScope());
$check($literal->total() === 1 && $literal->items()[0]['id'] === 106, 'LIKE wildcard input is literal.');
$date = $service->search(new FundingOverviewListQuery(startTime: '2026-10-15 02:00:00', endTimeExclusive: '2026-10-15 02:00:01'), new AllUsersScope());
$check($date->total() === 4, 'Dates filter the latest balance update, including exact start and excluding empty dates.');
$date = $service->search(new FundingOverviewListQuery(endTimeExclusive: '2026-10-15 02:00:00'), new AllUsersScope());
$check($date->total() === 0, 'End timestamp is exclusive.');
$empty = $service->search(new FundingOverviewListQuery(), new AgentIdsScope([]));
$check($empty->total() === 0, 'An empty scope must never become an unrestricted query.');
echo "Funding overview query tests passed.\n";
