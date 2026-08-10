<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\DataScope\AgentIdsScope;
use SixMm\Shared\OnlineUsers\OnlineUserQuery;
use SixMm\Shared\OnlineUsers\OnlineUserQueryService;
use SixMm\Shared\UserDetails\UserDetailQueryService;
use SixMm\Shared\Users\UserListQuery;
use SixMm\Shared\Users\UserListQueryService;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$database = new Capsule();
$database->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
]);
$database->setAsGlobal();

$schema = $database->getConnection()->getSchemaBuilder();
$schema->create('users', static function (Blueprint $table): void {
    $table->increments('user_id');
    $table->unsignedBigInteger('public_user_id');
    $table->unsignedBigInteger('agent_id');
    $table->string('username');
    $table->string('nick_name')->nullable();
    $table->unsignedSmallInteger('user_type')->default(1);
    $table->unsignedSmallInteger('vip_level')->default(1);
    $table->unsignedSmallInteger('risk_status')->default(0);
    $table->unsignedSmallInteger('risk_label')->default(0);
    $table->unsignedSmallInteger('online_status')->default(0);
    $table->string('last_login_ip')->nullable();
    $table->dateTime('last_login_at')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
    $table->dateTime('deleted_at')->nullable();
});
$schema->create('agent_user_bindings', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('agent_id');
    $table->unsignedBigInteger('platform_user_id');
    $table->string('agent_user_id');
    $table->string('bind_status');
});
$schema->create('user_assets', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('user_id');
    $table->decimal('wallet_balance', 24, 8)->default(0);
    $table->dateTime('deleted_at')->nullable();
});
$schema->create('user_daily_volume', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('user_id');
    $table->date('trade_date');
    $table->decimal('volume', 24, 8)->default(0);
});
$schema->create('user_daily_balance', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('user_id');
    $table->date('trade_date');
    $table->decimal('realized_pnl_cumulative', 24, 8)->default(0);
});

$database->getConnection()->table('users')->insert([
    [
        'user_id' => 1,
        'public_user_id' => 9001,
        'agent_id' => 10,
        'username' => 'alice',
        'nick_name' => 'Alice',
        'user_type' => 1,
        'vip_level' => 2,
        'online_status' => 1,
        'last_login_ip' => '127.0.0.1',
        'last_login_at' => '2026-08-01 10:00:00',
        'created_at' => '2026-08-01 09:00:00',
        'updated_at' => '2026-08-01 10:10:00',
    ],
    [
        'user_id' => 2,
        'public_user_id' => 9002,
        'agent_id' => 10,
        'username' => 'bob',
        'nick_name' => 'Bob',
        'user_type' => 2,
        'vip_level' => 3,
        'online_status' => 1,
        'last_login_ip' => '10.0.0.2',
        'last_login_at' => '2026-08-02 12:00:00',
        'created_at' => '2026-08-02 11:00:00',
        'updated_at' => '2026-08-02 12:10:00',
    ],
    [
        'user_id' => 3,
        'public_user_id' => 9003,
        'agent_id' => 10,
        'username' => 'offline',
        'nick_name' => null,
        'user_type' => 1,
        'vip_level' => 1,
        'online_status' => 0,
        'last_login_ip' => null,
        'last_login_at' => '2026-08-03 12:00:00',
        'created_at' => '2026-08-03 11:00:00',
        'updated_at' => '2026-08-03 12:10:00',
    ],
    [
        'user_id' => 4,
        'public_user_id' => 9004,
        'agent_id' => 20,
        'username' => 'outside',
        'nick_name' => null,
        'user_type' => 1,
        'vip_level' => 1,
        'online_status' => 1,
        'last_login_ip' => null,
        'last_login_at' => '2026-08-04 12:00:00',
        'created_at' => '2026-08-04 11:00:00',
        'updated_at' => '2026-08-04 12:10:00',
    ],
]);

$database->getConnection()->table('agent_user_bindings')->insert([
    [
        'agent_id' => 10,
        'platform_user_id' => 1,
        'agent_user_id' => 'external-alice',
        'bind_status' => 'BOUND',
    ],
    [
        'agent_id' => 10,
        'platform_user_id' => 2,
        'agent_user_id' => 'external-bob',
        'bind_status' => 'LOCKED',
    ],
]);

$database->getConnection()->table('user_assets')->insert([
    ['user_id' => 1, 'wallet_balance' => 100],
    ['user_id' => 2, 'wallet_balance' => 250],
    ['user_id' => 3, 'wallet_balance' => 50],
]);
$database->getConnection()->table('user_daily_volume')->insert([
    ['user_id' => 1, 'trade_date' => '2026-08-01', 'volume' => 1000],
    ['user_id' => 2, 'trade_date' => '2026-08-02', 'volume' => 2000],
]);
$database->getConnection()->table('user_daily_balance')->insert([
    ['user_id' => 1, 'trade_date' => '2026-08-01', 'realized_pnl_cumulative' => -10],
    ['user_id' => 2, 'trade_date' => '2026-08-02', 'realized_pnl_cumulative' => 20],
]);

$userListService = new UserListQueryService($database->getConnection());
$userList = $userListService->search(
    new UserListQuery(volumeSince: '2026-07-01'),
    new AgentIdsScope([10])
);
assertSameValue(3, $userList->total(), 'The shared user list should include all users inside the supplied scope.');
assertSameValue([9003, 9002, 9001], array_column($userList->items(), 'user_id'), 'The shared user list should sort by registration time descending.');
assertSameValue('Bob', $userList->items()[1]['nice_name'], 'The shared user list should expose the preferred display name.');

$filteredUserList = $userListService->search(
    new UserListQuery(
        keyword: 'external-bob',
        userType: 2,
        vipLevel: 3,
        createdAtStart: '2026-08-02 00:00:00',
        createdAtEndExclusive: '2026-08-03 00:00:00',
        volumeSince: '2026-07-01'
    ),
    new AgentIdsScope([10])
);
assertSameValue(1, $filteredUserList->total(), 'Combined user-list filters should return one row.');
assertSameValue(9002, $filteredUserList->items()[0]['user_id'], 'The matching shared user-list row should be returned.');

$walletSortedUserList = $userListService->search(
    new UserListQuery(orderBy: 'wallet_balance', orderDirection: 'desc', volumeSince: '2026-07-01'),
    new AgentIdsScope([10])
);
assertSameValue([9002, 9001, 9003], array_column($walletSortedUserList->items(), 'user_id'), 'Aggregate sorting should remain available.');
assertSameValue('250', $walletSortedUserList->items()[0]['wallet_balance'], 'Aggregate values should be serialized as strings.');

$includedUserList = $userListService->search(
    new UserListQuery(volumeSince: '2026-07-01', includedPlatformUserIds: [1]),
    new AgentIdsScope([10])
);
assertSameValue([9001], array_column($includedUserList->items(), 'user_id'), 'Included platform user IDs should constrain results before pagination.');

$emptyIncludedUserList = $userListService->search(
    new UserListQuery(volumeSince: '2026-07-01', includedPlatformUserIds: []),
    new AgentIdsScope([10])
);
assertSameValue(0, $emptyIncludedUserList->total(), 'An explicitly empty included-ID set must fail closed.');

$excludedUserList = $userListService->search(
    new UserListQuery(volumeSince: '2026-07-01', excludedPlatformUserIds: [1, 3]),
    new AgentIdsScope([10])
);
assertSameValue([9002], array_column($excludedUserList->items(), 'user_id'), 'Excluded platform user IDs should be applied before pagination.');

$service = new OnlineUserQueryService($database->getConnection());
$scoped = $service->search(new OnlineUserQuery(), new AgentIdsScope([10]));
assertSameValue(2, $scoped->total(), 'Only online users inside the scope should be counted.');
assertSameValue([9002, 9001], array_column($scoped->items(), 'user_id'), 'Default ordering should use last login descending.');
assertSameValue('external-alice', $scoped->items()[1]['agent_user_id'], 'The active external user binding should be projected.');

$filtered = $service->search(
    new OnlineUserQuery(
        keyword: 'external-bob',
        userType: 2,
        vipLevel: 3,
        createdAtStart: '2026-08-02 00:00:00',
        createdAtEndExclusive: '2026-08-03 00:00:00'
    ),
    new AgentIdsScope([10])
);
assertSameValue(1, $filtered->total(), 'Combined filters should select one matching row.');
assertSameValue(9002, $filtered->items()[0]['user_id'], 'The matching row should be returned.');

$emptyScope = $service->search(new OnlineUserQuery(), new AgentIdsScope([]));
assertSameValue(0, $emptyScope->total(), 'An empty scope must fail closed.');
assertSameValue([], $emptyScope->items(), 'An empty scope must not return rows.');

$detailService = new UserDetailQueryService($database->getConnection());
$detail = $detailService->findByPublicUserId(9001, new AgentIdsScope([10]));
assertSameValue(1, $detail?->platformUserId(), 'The internal platform user ID should be available to the host application.');
assertSameValue(9001, $detail?->toArray()['user_id'] ?? null, 'The public user ID should be projected to the shared response.');
assertSameValue('external-alice', $detail?->toArray()['agent_user_id'] ?? null, 'The active external user binding should be included.');

$outsideDetail = $detailService->findByPublicUserId(9004, new AgentIdsScope([10]));
assertSameValue(null, $outsideDetail, 'A user outside the supplied data scope must not be returned.');

$emptyScopeDetail = $detailService->findByPublicUserId(9001, new AgentIdsScope([]));
assertSameValue(null, $emptyScopeDetail, 'An empty detail scope must fail closed.');

fwrite(STDOUT, "Shared user-list, online-user, and user-detail contract tests passed.\n");
