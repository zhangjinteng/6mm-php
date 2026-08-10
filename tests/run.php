<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\AccountChangeLogs\AccountChangeLogListQuery;
use SixMm\Shared\AccountChangeLogs\AccountChangeLogListQueryService;
use SixMm\Shared\CurrentOrders\CurrentOrderListQuery;
use SixMm\Shared\CurrentOrders\CurrentOrderUserContextService;
use SixMm\Shared\CurrentPositions\CurrentPositionListQuery;
use SixMm\Shared\CurrentPositions\CurrentPositionUserContextService;
use SixMm\Shared\ConditionOrders\ConditionOrderKind;
use SixMm\Shared\ConditionOrders\ConditionOrderLifecycle;
use SixMm\Shared\ConditionOrders\ConditionOrderListQuery;
use SixMm\Shared\ConditionOrders\ConditionOrderListQueryService;
use SixMm\Shared\Contracts\UserTradingActionGateway;
use SixMm\Shared\DataScope\AgentIdsScope;
use SixMm\Shared\DataScope\AllUsersScope;
use SixMm\Shared\HistoryPositions\HistoryPositionListQuery;
use SixMm\Shared\HistoryPositions\HistoryPositionUserContextService;
use SixMm\Shared\HistoryOrders\HistoryOrderListQuery;
use SixMm\Shared\HistoryOrders\HistoryOrderUserContextService;
use SixMm\Shared\Liquidations\LiquidationListQuery;
use SixMm\Shared\Liquidations\LiquidationUserContextService;
use SixMm\Shared\TradeFills\TradeFillListQuery;
use SixMm\Shared\TradeFills\TradeFillUserContextService;
use SixMm\Shared\OnlineUsers\OnlineUserQuery;
use SixMm\Shared\OnlineUsers\OnlineUserQueryService;
use SixMm\Shared\UserDetails\UserDetailQueryService;
use SixMm\Shared\UserActions\UserTradingActionService;
use SixMm\Shared\UserAssets\UserAssetListQuery;
use SixMm\Shared\UserAssets\UserAssetListQueryService;
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
    $table->increments('ua_id');
    $table->unsignedBigInteger('user_id');
    $table->string('asset_type')->default('USDT');
    $table->decimal('wallet_balance', 24, 8)->default(0);
    $table->decimal('frozen_balance', 24, 8)->default(0);
    $table->decimal('realized_pnl', 24, 8)->default(0);
    $table->unsignedBigInteger('version')->default(0);
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
    $table->dateTime('deleted_at')->nullable();
});
$schema->create('user_account_change_log', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('user_id');
    $table->unsignedSmallInteger('user_type')->nullable();
    $table->unsignedBigInteger('agent_id')->nullable();
    $table->unsignedInteger('profile_version')->nullable();
    $table->string('symbol')->nullable();
    $table->string('asset_type')->nullable();
    $table->decimal('amount', 24, 8)->default(0);
    $table->decimal('wallet_balance_before', 24, 8)->default(0);
    $table->decimal('wallet_balance_after', 24, 8)->default(0);
    $table->decimal('frozen_balance_before', 24, 8)->default(0);
    $table->decimal('frozen_balance_after', 24, 8)->default(0);
    $table->string('change_type');
    $table->string('reference_id')->nullable();
    $table->text('description')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
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
$schema->create('condition_orders', static function (Blueprint $table): void {
    $table->string('condition_id')->primary();
    $table->unsignedBigInteger('user_id');
    $table->string('symbol');
    $table->string('trigger_type');
    $table->decimal('trigger_price', 24, 8)->default(0);
    $table->string('working_type')->nullable();
    $table->decimal('callback_rate', 10, 6)->nullable();
    $table->decimal('activate_price', 24, 8)->nullable();
    $table->string('side');
    $table->string('order_type');
    $table->decimal('quantity', 24, 8)->default(0);
    $table->decimal('price', 24, 8)->nullable();
    $table->string('time_in_force')->nullable();
    $table->boolean('reduce_only')->default(false);
    $table->boolean('close_position')->default(false);
    $table->unsignedSmallInteger('price_type')->default(0);
    $table->string('strategy_id')->nullable();
    $table->unsignedSmallInteger('strategy_sub_id')->nullable();
    $table->string('order_id')->nullable();
    $table->integer('first_driven_id')->default(0);
    $table->integer('second_driven_id')->default(0);
    $table->string('first_driven_on')->nullable();
    $table->string('first_trigger')->nullable();
    $table->string('second_driven_on')->nullable();
    $table->string('second_trigger')->nullable();
    $table->integer('trigger_status');
    $table->string('generated_order_id')->nullable();
    $table->string('client_order_id')->nullable();
    $table->unsignedSmallInteger('leverage')->default(0);
    $table->unsignedSmallInteger('margin_mode')->default(0);
    $table->unsignedSmallInteger('user_type')->nullable();
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
    $table->dateTime('deleted_at')->nullable();
});
$schema->create('orders', static function (Blueprint $table): void {
    $table->string('order_id')->primary();
    $table->decimal('filled_quantity', 24, 8)->default(0);
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
    [
        'user_id' => 5,
        'public_user_id' => 9005,
        'agent_id' => 0,
        'username' => 'platform-user',
        'nick_name' => 'Platform User',
        'user_type' => 1,
        'vip_level' => 1,
        'online_status' => 0,
        'last_login_ip' => null,
        'last_login_at' => null,
        'created_at' => '2026-08-05 11:00:00',
        'updated_at' => '2026-08-05 12:10:00',
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
    ['ua_id' => 1, 'user_id' => 1, 'wallet_balance' => 100, 'frozen_balance' => 5, 'realized_pnl' => 10],
    ['ua_id' => 2, 'user_id' => 2, 'wallet_balance' => 250, 'frozen_balance' => 8, 'realized_pnl' => -2],
    ['ua_id' => 3, 'user_id' => 3, 'wallet_balance' => 50, 'frozen_balance' => 0, 'realized_pnl' => 0],
    ['ua_id' => 4, 'user_id' => 4, 'wallet_balance' => 999, 'frozen_balance' => 0, 'realized_pnl' => 0],
    ['ua_id' => 5, 'user_id' => 5, 'wallet_balance' => 75, 'frozen_balance' => 0, 'realized_pnl' => 0],
]);
$conditionRow = static function (
    string $conditionId,
    int $userId,
    string $triggerType,
    bool $closePosition,
    int $triggerStatus,
    int $snapshotUserType,
    string $createdAt
): array {
    return [
        'condition_id' => $conditionId,
        'user_id' => $userId,
        'symbol' => 'BTCUSDT',
        'trigger_type' => $triggerType,
        'trigger_price' => 60000,
        'working_type' => 'MARK_PRICE',
        'side' => 'sell',
        'order_type' => 'market',
        'quantity' => 1,
        'reduce_only' => true,
        'close_position' => $closePosition,
        'trigger_status' => $triggerStatus,
        'generated_order_id' => 'generated-' . $conditionId,
        'client_order_id' => 'client-' . $conditionId,
        'leverage' => 20,
        'margin_mode' => 1,
        'user_type' => $snapshotUserType,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ];
};
$database->getConnection()->table('condition_orders')->insert([
    $conditionRow('condition-current', 1, 'trailing_stop', false, 0, 2, '2026-08-06 10:00:00'),
    $conditionRow('tp-sl-current', 2, 'take_profit_market', true, 1, 1, '2026-08-06 11:00:00'),
    $conditionRow('condition-history', 1, 'trailing_stop', false, 3, 2, '2026-08-05 10:00:00'),
    $conditionRow('2085983764139225088', 2, 'stop_market', true, 2, 1, '2026-08-05 11:00:00'),
    $conditionRow('condition-outside', 4, 'trailing_stop', false, 0, 1, '2026-08-06 12:00:00'),
]);
$database->getConnection()->table('orders')->insert([
    ['order_id' => 'generated-condition-history', 'filled_quantity' => 0.75],
]);
$database->getConnection()->table('user_account_change_log')->insert([
    [
        'id' => 100,
        'user_id' => 1,
        'user_type' => 1,
        'agent_id' => 10,
        'symbol' => 'BTCUSDT',
        'asset_type' => 'USDT',
        'amount' => -1,
        'wallet_balance_before' => 100,
        'wallet_balance_after' => 99,
        'change_type' => 'HANDLING_FEE',
        'reference_id' => 'trade-100',
        'created_at' => '2026-08-02 10:00:00',
    ],
    [
        'id' => 101,
        'user_id' => 2,
        'user_type' => 1,
        'agent_id' => 10,
        'symbol' => 'ETHUSDT',
        'asset_type' => 'USDT',
        'amount' => 2,
        'wallet_balance_before' => 250,
        'wallet_balance_after' => 252,
        'change_type' => 'FUNDING_FEE_SETTLE',
        'reference_id' => 'funding-101',
        'created_at' => '2026-08-03 10:00:00',
    ],
    [
        'id' => 102,
        'user_id' => 4,
        'user_type' => 1,
        'agent_id' => 20,
        'symbol' => 'BTCUSDT',
        'asset_type' => 'USDT',
        'amount' => 999,
        'wallet_balance_before' => 0,
        'wallet_balance_after' => 999,
        'change_type' => 'REALIZED_PNL',
        'reference_id' => 'outside-102',
        'created_at' => '2026-08-04 10:00:00',
    ],
    [
        'id' => 103,
        'user_id' => 2,
        'user_type' => 2,
        'agent_id' => 10,
        'symbol' => 'SOLUSDT',
        'asset_type' => 'USDT',
        'amount' => 10,
        'wallet_balance_before' => 252,
        'wallet_balance_after' => 262,
        'change_type' => 'agent_transfer',
        'reference_id' => 'transfer-in-103',
        'created_at' => '2026-08-05 10:00:00',
    ],
    [
        'id' => 104,
        'user_id' => 2,
        'user_type' => 2,
        'agent_id' => 10,
        'symbol' => 'SOLUSDT',
        'asset_type' => 'USDT',
        'amount' => -5,
        'wallet_balance_before' => 262,
        'wallet_balance_after' => 257,
        'change_type' => 'agent_transfer_all_out',
        'reference_id' => 'transfer-out-104',
        'created_at' => '2026-08-06 10:00:00',
    ],
    [
        'id' => 105,
        'user_id' => 1,
        'user_type' => 1,
        'agent_id' => 10,
        'symbol' => 'btcusdt',
        'asset_type' => 'USDT',
        'amount' => 3,
        'wallet_balance_before' => 99,
        'wallet_balance_after' => 102,
        'change_type' => 'REALIZED_PNL',
        'reference_id' => 'pnl-105',
        'created_at' => '2026-08-07 10:00:00',
    ],
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

$platformRelationUserList = $userListService->search(
    new UserListQuery(volumeSince: '2026-07-01', agentId: 0),
    new AllUsersScope()
);
assertSameValue([9005], array_column($platformRelationUserList->items(), 'user_id'), 'Recommendation filtering should support platform users with agent ID zero.');

$userAssetService = new UserAssetListQueryService($database->getConnection());
$userAssets = $userAssetService->search(
    new UserAssetListQuery(),
    new AgentIdsScope([10])
);
assertSameValue(3, $userAssets->total(), 'The shared asset list should include only assets inside the supplied scope.');
assertSameValue([3, 2, 1], array_column($userAssets->items(), 'ua_id'), 'The shared asset list should default to asset ID descending.');
assertSameValue([9003, 9002, 9001], array_column($userAssets->items(), 'user_id'), 'The shared asset list should expose public UIDs.');
assertSameValue(2, $userAssets->items()[1]['platform_user_id'], 'The internal platform user ID should remain available to the host adapter.');
assertSameValue('250', $userAssets->items()[1]['wallet_balance'], 'Asset amounts should be serialized as strings.');

$filteredUserAssets = $userAssetService->search(
    new UserAssetListQuery(keyword: 'external-bob', userType: 2, agentId: 10),
    new AllUsersScope()
);
assertSameValue(1, $filteredUserAssets->total(), 'External-ID, user-type, and recommendation filters should compose.');
assertSameValue(9002, $filteredUserAssets->items()[0]['user_id'], 'The filtered shared asset row should be returned.');
assertSameValue('Bob', $filteredUserAssets->items()[0]['nice_name'], 'The preferred user display name should be projected.');

$platformRelationUserAssets = $userAssetService->search(
    new UserAssetListQuery(agentId: 0),
    new AllUsersScope()
);
assertSameValue([9005], array_column($platformRelationUserAssets->items(), 'user_id'), 'Asset recommendation filtering should support platform users with agent ID zero.');

$uidSortedUserAssets = $userAssetService->search(
    new UserAssetListQuery(orderBy: 'user_id', orderDirection: 'asc'),
    new AgentIdsScope([10])
);
assertSameValue([9001, 9002, 9003], array_column($uidSortedUserAssets->items(), 'user_id'), 'Public UID sorting should remain available.');

$internalIdSortedUserAssets = $userAssetService->search(
    new UserAssetListQuery(orderBy: 'platform_user_id', orderDirection: 'asc'),
    new AgentIdsScope([10])
);
assertSameValue([1, 2, 3], array_column($internalIdSortedUserAssets->items(), 'platform_user_id'), 'Internal platform user ID sorting should remain available.');

$emptyAssetScope = $userAssetService->search(new UserAssetListQuery(), new AgentIdsScope([]));
assertSameValue(0, $emptyAssetScope->total(), 'An empty asset scope must fail closed.');
assertSameValue([], $emptyAssetScope->items(), 'An empty asset scope must not return rows.');

$accountChangeService = new AccountChangeLogListQueryService($database->getConnection());
$accountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(pageSize: 2),
    new AgentIdsScope([10])
);
assertSameValue([105, 104], array_column($accountChanges->items(), 'id'), 'Account changes should default to ID descending.');
assertSameValue(9001, $accountChanges->items()[0]['user_id'], 'Account changes should expose the public UID.');
assertSameValue(1, $accountChanges->items()[0]['platform_user_id'], 'The internal platform user ID should remain available.');
assertSameValue('Alice', $accountChanges->items()[0]['user']['nice_name'], 'The preferred display name should be projected.');
assertSameValue(true, $accountChanges->hasMore(), 'The first cursor page should report a following page.');
assertSameValue(false, $accountChanges->hasPrevious(), 'The first cursor page should not report a previous page.');

$nextAccountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(pageSize: 2, cursor: $accountChanges->nextCursor()),
    new AgentIdsScope([10])
);
assertSameValue([103, 101], array_column($nextAccountChanges->items(), 'id'), 'The next cursor should continue the stable ID order.');
assertSameValue(true, $nextAccountChanges->hasPrevious(), 'A following page should expose a previous cursor.');

$previousAccountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(pageSize: 2, cursor: $nextAccountChanges->previousCursor()),
    new AgentIdsScope([10])
);
assertSameValue([105, 104], array_column($previousAccountChanges->items(), 'id'), 'The previous cursor should return to the prior page.');

$amountSortedAccountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(pageSize: 2, orderBy: 'amount', orderDirection: 'desc'),
    new AgentIdsScope([10])
);
assertSameValue([103, 105], array_column($amountSortedAccountChanges->items(), 'id'), 'Amount sorting should use a stable ID tie-breaker.');
$nextAmountSortedAccountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(
        pageSize: 2,
        cursor: $amountSortedAccountChanges->nextCursor(),
        orderBy: 'amount',
        orderDirection: 'desc'
    ),
    new AgentIdsScope([10])
);
assertSameValue([101, 100], array_column($nextAmountSortedAccountChanges->items(), 'id'), 'Sorted cursors should continue from the last sort value.');

$externalAccountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(keyword: 'external-bob', userType: 2),
    new AgentIdsScope([10])
);
assertSameValue([104, 103, 101], array_column($externalAccountChanges->items(), 'id'), 'External ID and current user type filters should compose.');
assertSameValue(1, $externalAccountChanges->items()[2]['user_type'], 'Each row should preserve the historical user type stored with the account change.');
assertSameValue('external-bob', $externalAccountChanges->items()[0]['agent_user_id'], 'The active external binding should be projected.');

$symbolAccountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(symbol: 'bTc'),
    new AgentIdsScope([10])
);
assertSameValue([105, 100], array_column($symbolAccountChanges->items(), 'id'), 'Symbol filtering should be partial and case-insensitive.');

$transferInChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(changeType: 'agent_transfer'),
    new AgentIdsScope([10])
);
assertSameValue([103], array_column($transferInChanges->items(), 'id'), 'Transfer-in filtering should require a positive amount.');

$transferOutChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(changeType: 'agent_transfer_all_out'),
    new AgentIdsScope([10])
);
assertSameValue([104], array_column($transferOutChanges->items(), 'id'), 'Transfer-out filtering should include negative transfer variants.');

$timeAccountChanges = $accountChangeService->search(
    new AccountChangeLogListQuery(
        createdAtStart: '2026-08-03 00:00:00',
        createdAtEndExclusive: '2026-08-06 00:00:00'
    ),
    new AgentIdsScope([10])
);
assertSameValue([103, 101], array_column($timeAccountChanges->items(), 'id'), 'Time filtering should use an exclusive end boundary.');

$emptyAccountChangeScope = $accountChangeService->search(new AccountChangeLogListQuery(), new AgentIdsScope([]));
assertSameValue([], $emptyAccountChangeScope->items(), 'An empty account-change scope must fail closed.');

$positionQuery = new CurrentPositionListQuery(
    page: -2,
    pageSize: 500,
    keyword: ' external-bob ',
    userType: 0,
    symbol: ' btcusdt ',
    marginMode: 7,
    positionSide: ' LONG ',
    leverage: -1,
    orderBy: 'unsupported',
    orderDirection: 'ASC'
);
assertSameValue(1, $positionQuery->page(), 'Current-position pages should fail back to the first page.');
assertSameValue(100, $positionQuery->pageSize(), 'Current-position page size should be capped.');
assertSameValue('external-bob', $positionQuery->keyword(), 'Current-position keywords should be trimmed.');
assertSameValue('BTCUSDT', $positionQuery->symbol(), 'Current-position symbols should be normalized.');
assertSameValue(null, $positionQuery->marginMode(), 'Unsupported margin modes should be ignored.');
assertSameValue('long', $positionQuery->positionSide(), 'Position sides should be normalized.');
assertSameValue('position_id', $positionQuery->orderBy(), 'Unsupported position sorting should fail closed.');
assertSameValue('asc', $positionQuery->orderDirection(), 'Position sort direction should be normalized.');

$positionContext = new CurrentPositionUserContextService($database->getConnection());
assertSameValue(
    [1, 2, 3],
    $positionContext->scopedPlatformUserIds(new AgentIdsScope([10])),
    'Current positions should resolve only platform users inside the supplied scope.'
);
assertSameValue(
    [2],
    $positionContext->matchingPlatformUserIds('external-bob', new AgentIdsScope([10])),
    'Current-position identity search should include active external bindings.'
);
assertSameValue(
    [1],
    $positionContext->matchingPlatformUserIds('Alice', new AgentIdsScope([10])),
    'Current-position identity search should include preferred display names.'
);
assertSameValue(
    [],
    $positionContext->scopedPlatformUserIds(new AgentIdsScope([])),
    'An empty current-position scope must fail closed.'
);

$hydratedPositions = $positionContext->hydrateRows([
    [
        'id' => 7001,
        'user_id' => 1,
        'user_type' => 2,
        'symbol' => 'BTCUSDT',
        'quantity' => '0.1',
    ],
    [
        'id' => 7002,
        'user_id' => 4,
        'user_type' => 1,
        'symbol' => 'ETHUSDT',
        'quantity' => '1',
    ],
], new AgentIdsScope([10]));
assertSameValue([7001], array_column($hydratedPositions, 'id'), 'Hydration must discard source rows outside the supplied scope.');
assertSameValue(1, $hydratedPositions[0]['platform_user_id'], 'Hydration should preserve the internal platform user ID.');
assertSameValue(9001, $hydratedPositions[0]['user_id'], 'Hydration should expose the public user UID.');
assertSameValue('external-alice', $hydratedPositions[0]['agent_user_id'], 'Hydration should expose the active external binding.');
assertSameValue('Alice', $hydratedPositions[0]['nice_name'], 'Hydration should expose the preferred display name.');
assertSameValue(2, $hydratedPositions[0]['user']['user_type'], 'A valid source user type should remain authoritative.');
assertSameValue('100', $hydratedPositions[0]['wallet_balance'], 'Hydration should expose account balances as strings.');

$liquidationQuery = new LiquidationListQuery(
    page: -3,
    pageSize: 500,
    keyword: ' external-alice ',
    userType: 9,
    productCategory: ' SWAP ',
    symbol: ' btcusdt ',
    positionSide: ' LONG ',
    occurredAtStart: ' 2026-08-01 00:00:00 ',
    occurredAtEnd: ' 2026-08-02 23:59:59 ',
    orderBy: 'unsupported',
    orderDirection: 'ASC'
);
assertSameValue(1, $liquidationQuery->page(), 'Liquidation pages should fail back to the first page.');
assertSameValue(100, $liquidationQuery->pageSize(), 'Liquidation page size should be capped.');
assertSameValue('external-alice', $liquidationQuery->keyword(), 'Liquidation keywords should be trimmed.');
assertSameValue(null, $liquidationQuery->userType(), 'Unsupported liquidation user types should be ignored.');
assertSameValue('swap', $liquidationQuery->productCategory(), 'Product categories should be normalized.');
assertSameValue('BTCUSDT', $liquidationQuery->symbol(), 'Liquidation symbols should be normalized.');
assertSameValue('long', $liquidationQuery->positionSide(), 'Liquidation sides should be normalized.');
assertSameValue('occurred_at', $liquidationQuery->orderBy(), 'Unsupported liquidation sorting should fail closed.');
assertSameValue('asc', $liquidationQuery->orderDirection(), 'Liquidation sort direction should be normalized.');

$liquidationContext = new LiquidationUserContextService($database->getConnection());
assertSameValue(
    [1],
    $liquidationContext->matchingPlatformUserIds('external-alice', new AgentIdsScope([10])),
    'Liquidation identity search should include active external bindings.'
);
assertSameValue(
    [1],
    $liquidationContext->matchingPlatformUserIds('Alice', new AgentIdsScope([10])),
    'Liquidation identity search should include preferred display names.'
);

$hydratedLiquidations = $liquidationContext->hydrateRows([
    ['position_id' => 7101, 'user_id' => 1, 'user_type' => 2, 'symbol' => 'BTCUSDT'],
    ['position_id' => 7102, 'user_id' => 2, 'user_type' => 0, 'symbol' => 'ETHUSDT'],
    ['position_id' => 7103, 'user_id' => 4, 'user_type' => 1, 'symbol' => 'BNBUSDT'],
], new AgentIdsScope([10]));
assertSameValue([7101, 7102], array_column($hydratedLiquidations, 'position_id'), 'Liquidation hydration must discard source rows outside the supplied scope.');
assertSameValue(1, $hydratedLiquidations[0]['platform_user_id'], 'Liquidation hydration should preserve the internal platform user ID.');
assertSameValue(9001, $hydratedLiquidations[0]['user_id'], 'Liquidation hydration should expose the public user UID.');
assertSameValue('external-alice', $hydratedLiquidations[0]['agent_user_id'], 'Liquidation hydration should expose the active external binding.');
assertSameValue(2, $hydratedLiquidations[0]['user_type'], 'A valid historical liquidation user type should remain authoritative.');
assertSameValue(2, $hydratedLiquidations[1]['user_type'], 'Liquidation hydration should fall back to the current user type.');

$tradeFillQuery = new TradeFillListQuery(
    page: -4,
    pageSize: 500,
    keyword: ' external-alice ',
    positionId: -1,
    placeType: ' liquidation ',
    userType: 9,
    symbol: ' btcusdt ',
    marginMode: 8,
    side: ' BUY ',
    roleType: ' TAKER ',
    tradeTimeStart: ' 2026-08-01 00:00:00 ',
    tradeTimeEnd: ' 2026-08-02 23:59:59 ',
    orderBy: 'unsupported',
    orderDirection: 'ASC'
);
assertSameValue(1, $tradeFillQuery->page(), 'Trade-fill pages should fail back to the first page.');
assertSameValue(100, $tradeFillQuery->pageSize(), 'Trade-fill page size should be capped.');
assertSameValue('external-alice', $tradeFillQuery->keyword(), 'Trade-fill keywords should be trimmed.');
assertSameValue(null, $tradeFillQuery->positionId(), 'Invalid trade-fill position IDs should be ignored.');
assertSameValue('LIQUIDATION', $tradeFillQuery->placeType(), 'Trade-fill placement types should be normalized.');
assertSameValue(null, $tradeFillQuery->userType(), 'Unsupported trade-fill user types should be ignored.');
assertSameValue('BTCUSDT', $tradeFillQuery->symbol(), 'Trade-fill symbols should be normalized.');
assertSameValue(null, $tradeFillQuery->marginMode(), 'Unsupported trade-fill margin modes should be ignored.');
assertSameValue('buy', $tradeFillQuery->side(), 'Trade-fill sides should be normalized.');
assertSameValue('taker', $tradeFillQuery->roleType(), 'Trade-fill roles should be normalized.');
assertSameValue('trade_time', $tradeFillQuery->orderBy(), 'Unsupported trade-fill sorting should fail closed.');
assertSameValue('asc', $tradeFillQuery->orderDirection(), 'Trade-fill sort direction should be normalized.');

$tradeFillContext = new TradeFillUserContextService($database->getConnection());
assertSameValue(
    [1, 2, 3],
    $tradeFillContext->scopedPlatformUserIds(new AgentIdsScope([10])),
    'Trade fills should resolve only platform users inside the supplied scope.'
);
assertSameValue(
    [2],
    $tradeFillContext->scopedPlatformUserIds(new AgentIdsScope([10]), 2),
    'Trade fills should resolve current-type fallback IDs inside the supplied scope.'
);
assertSameValue(
    [1],
    $tradeFillContext->matchingPlatformUserIds('external-alice', new AgentIdsScope([10])),
    'Trade-fill identity search should include active external bindings.'
);
assertSameValue(
    [1],
    $tradeFillContext->matchingPlatformUserIds('Alice', new AgentIdsScope([10])),
    'Trade-fill identity search should include preferred display names.'
);

$hydratedTradeFills = $tradeFillContext->hydrateRows([
    ['fill_id' => 7201, 'user_id' => 1, 'user_type' => 2, 'symbol' => 'BTCUSDT'],
    ['fill_id' => 7202, 'user_id' => 2, 'user_type' => 0, 'symbol' => 'ETHUSDT'],
    ['fill_id' => 7203, 'user_id' => 4, 'user_type' => 1, 'symbol' => 'BNBUSDT'],
], new AgentIdsScope([10]));
assertSameValue([7201, 7202], array_column($hydratedTradeFills, 'fill_id'), 'Trade-fill hydration must discard source rows outside the supplied scope.');
assertSameValue(1, $hydratedTradeFills[0]['platform_user_id'], 'Trade-fill hydration should preserve the internal platform user ID.');
assertSameValue(9001, $hydratedTradeFills[0]['user_id'], 'Trade-fill hydration should expose the public user UID.');
assertSameValue('external-alice', $hydratedTradeFills[0]['agent_user_id'], 'Trade-fill hydration should expose the active external binding.');
assertSameValue(2, $hydratedTradeFills[0]['user_type'], 'A valid historical trade-fill user type should remain authoritative.');
assertSameValue(2, $hydratedTradeFills[1]['user_type'], 'Trade-fill hydration should fall back to the current user type.');

$orderQuery = new CurrentOrderListQuery(
    pageSize: 500,
    cursor: ' next-page ',
    keyword: ' external-bob ',
    userType: 9,
    symbol: ' btcusdt ',
    orderType: ' LIMIT ',
    marginMode: 8,
    side: ' SELL ',
    leverage: -2,
    reduceOnly: false,
    makerOnly: true,
    orderStatuses: [2, '2', -1, 0],
    createdAtStart: ' 2026-08-01 00:00:00 ',
    createdAtEnd: ' 2026-08-02 23:59:59 '
);
assertSameValue(100, $orderQuery->pageSize(), 'Current-order page size should be capped.');
assertSameValue('next-page', $orderQuery->cursor(), 'Current-order cursors should be trimmed.');
assertSameValue('external-bob', $orderQuery->keyword(), 'Current-order keywords should be trimmed.');
assertSameValue(null, $orderQuery->userType(), 'Unsupported current-order user types should be ignored.');
assertSameValue('BTCUSDT', $orderQuery->symbol(), 'Current-order symbols should be normalized.');
assertSameValue('limit', $orderQuery->orderType(), 'Current-order types should be normalized.');
assertSameValue(null, $orderQuery->marginMode(), 'Unsupported current-order margin modes should be ignored.');
assertSameValue('sell', $orderQuery->side(), 'Current-order sides should be normalized.');
assertSameValue(null, $orderQuery->leverage(), 'Invalid current-order leverage should be ignored.');
assertSameValue([2, 0], $orderQuery->orderStatuses(), 'Current-order statuses should be unique non-negative integers.');
assertSameValue(false, $orderQuery->sourceFilters()['reduce_only'], 'Boolean false must remain a valid source filter.');

$orderContext = new CurrentOrderUserContextService($database->getConnection());
assertSameValue(
    [2],
    $orderContext->scopedPlatformUserIds(new AgentIdsScope([10]), 2),
    'Current orders should apply the optional current user-type filter inside the supplied scope.'
);
assertSameValue(
    [2],
    $orderContext->matchingPlatformUserIds('external-bob', new AgentIdsScope([10]), 2),
    'Current-order identity search should include active external bindings.'
);
assertSameValue(
    [1],
    $orderContext->matchingPlatformUserIds('Alice', new AgentIdsScope([10])),
    'Current-order identity search should include preferred display names.'
);
assertSameValue(
    [],
    $orderContext->scopedPlatformUserIds(new AgentIdsScope([])),
    'An empty current-order scope must fail closed.'
);

$hydratedOrders = $orderContext->hydrateRows([
    [
        'order_id' => 8001,
        'user_id' => 1,
        'user_type' => 2,
        'symbol' => 'BTCUSDT',
    ],
    [
        'order_id' => 8002,
        'user_id' => 4,
        'user_type' => 1,
        'symbol' => 'ETHUSDT',
    ],
], new AgentIdsScope([10]));
assertSameValue([8001], array_column($hydratedOrders, 'order_id'), 'Current-order hydration must discard source rows outside the supplied scope.');
assertSameValue(1, $hydratedOrders[0]['platform_user_id'], 'Current-order hydration should preserve the internal platform user ID.');
assertSameValue(9001, $hydratedOrders[0]['user_id'], 'Current-order hydration should expose the public user UID.');
assertSameValue('external-alice', $hydratedOrders[0]['agent_user_id'], 'Current-order hydration should expose the active external binding.');
assertSameValue(1, $hydratedOrders[0]['user']['user_type'], 'Current orders should use the current scoped user type.');

$historyPositionQuery = new HistoryPositionListQuery(
    page: -5,
    pageSize: 500,
    keyword: ' external-bob ',
    userType: 9,
    symbol: ' btcusdt ',
    marginMode: 7,
    positionSide: ' SHORT ',
    tradeSide: ' SELL ',
    leverage: -3,
    triggerMode: 8,
    statuses: [4, '4', 2, 9],
    closedAtStart: ' 2026-08-01 00:00:00 ',
    closedAtEnd: ' 2026-08-02 23:59:59 ',
    orderBy: 'unsupported',
    orderDirection: 'ASC'
);
assertSameValue(1, $historyPositionQuery->page(), 'History-position pages should fail back to the first page.');
assertSameValue(100, $historyPositionQuery->pageSize(), 'History-position page size should be capped.');
assertSameValue('external-bob', $historyPositionQuery->keyword(), 'History-position keywords should be trimmed.');
assertSameValue(null, $historyPositionQuery->userType(), 'Unsupported history-position user types should be ignored.');
assertSameValue('BTCUSDT', $historyPositionQuery->symbol(), 'History-position symbols should be normalized.');
assertSameValue(null, $historyPositionQuery->marginMode(), 'Unsupported history-position margin modes should be ignored.');
assertSameValue('short', $historyPositionQuery->positionSide(), 'History-position sides should be normalized.');
assertSameValue('sell', $historyPositionQuery->tradeSide(), 'History-position trade sides should be normalized.');
assertSameValue(null, $historyPositionQuery->leverage(), 'Invalid history-position leverage should be ignored.');
assertSameValue(null, $historyPositionQuery->triggerMode(), 'Unsupported trigger modes should be ignored.');
assertSameValue([2, 4], $historyPositionQuery->statuses(), 'History-position statuses should be unique allowed values.');
assertSameValue('closed_at', $historyPositionQuery->orderBy(), 'Unsupported history-position sorting should use close time.');
assertSameValue('asc', $historyPositionQuery->orderDirection(), 'History-position sort direction should be normalized.');

$historyPositionContext = new HistoryPositionUserContextService($database->getConnection());
assertSameValue(
    [1, 2, 3],
    $historyPositionContext->scopedPlatformUserIds(new AgentIdsScope([10])),
    'History positions should resolve platform users inside the supplied scope.'
);
assertSameValue(
    [2],
    $historyPositionContext->platformUserIdsForType(new AgentIdsScope([10]), 2),
    'History-position current user-type fallbacks should remain scope-safe.'
);
assertSameValue(
    [2],
    $historyPositionContext->matchingPlatformUserIds('external-bob', new AgentIdsScope([10])),
    'History-position identity search should include active external bindings.'
);
assertSameValue(
    [1],
    $historyPositionContext->matchingPlatformUserIds('Alice', new AgentIdsScope([10])),
    'History-position identity search should include preferred display names.'
);

$hydratedHistoryPositions = $historyPositionContext->hydrateRows([
    ['position_id' => 9101, 'user_id' => 1, 'user_type' => 2, 'symbol' => 'BTCUSDT'],
    ['position_id' => 9102, 'user_id' => 2, 'user_type' => 0, 'symbol' => 'ETHUSDT'],
    ['position_id' => 9103, 'user_id' => 4, 'user_type' => 1, 'symbol' => 'BNBUSDT'],
], new AgentIdsScope([10]));
assertSameValue([9101, 9102], array_column($hydratedHistoryPositions, 'position_id'), 'History-position hydration must discard source rows outside the supplied scope.');
assertSameValue(1, $hydratedHistoryPositions[0]['platform_user_id'], 'History-position hydration should preserve the internal platform user ID.');
assertSameValue(9001, $hydratedHistoryPositions[0]['user_id'], 'History-position hydration should expose the public user UID.');
assertSameValue('external-alice', $hydratedHistoryPositions[0]['agent_user_id'], 'History-position hydration should expose the active external binding.');
assertSameValue(2, $hydratedHistoryPositions[0]['user_type'], 'A valid history-position user type should remain authoritative.');
assertSameValue(2, $hydratedHistoryPositions[1]['user_type'], 'History-position hydration should fall back to the current user type.');

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

$tradingGateway = new class implements UserTradingActionGateway {
    /** @var int[] */
    public array $cancelledUserIds = [];

    /** @var int[] */
    public array $closedUserIds = [];

    public function cancelAllOrders(int $platformUserId): void
    {
        $this->cancelledUserIds[] = $platformUserId;
    }

    public function closeAllPositions(int $platformUserId): void
    {
        $this->closedUserIds[] = $platformUserId;
    }
};
$tradingActions = new UserTradingActionService($detailService, $tradingGateway);
assertSameValue(true, $tradingActions->cancelAllOrders(9001, new AgentIdsScope([10])), 'Cancel-all should resolve an authorized public UID.');
assertSameValue([1], $tradingGateway->cancelledUserIds, 'Cancel-all should call the host gateway with the internal platform user ID.');
assertSameValue(true, $tradingActions->closeAllPositions(9002, new AgentIdsScope([10])), 'Close-all should resolve an authorized public UID.');
assertSameValue([2], $tradingGateway->closedUserIds, 'Close-all should call the host gateway with the internal platform user ID.');
assertSameValue(false, $tradingActions->cancelAllOrders(9004, new AgentIdsScope([10])), 'Trading actions must reject users outside the supplied scope.');
assertSameValue([1], $tradingGateway->cancelledUserIds, 'Rejected actions must not call the host gateway.');

assertSameValue(false, ConditionOrderKind::CONDITION->closePosition(), 'Condition-order preset should exclude close-position orders.');
assertSameValue(true, ConditionOrderKind::TP_SL->closePosition(), 'TP/SL preset should include close-position orders.');
assertSameValue(null, ConditionOrderKind::ALL->closePosition(), 'The compatibility preset should not constrain close-position orders.');
assertSameValue(
    ['stop_market', 'stop_limit', 'take_profit_market', 'take_profit_limit'],
    ConditionOrderKind::TP_SL->triggerTypes(),
    'TP/SL preset should own its supported trigger types.'
);
assertSameValue([-1, 0, 1], ConditionOrderLifecycle::CURRENT->triggerStatuses(), 'Current lifecycle should own active trigger statuses.');
assertSameValue([2, 3, 4, 5], ConditionOrderLifecycle::HISTORY->triggerStatuses(), 'History lifecycle should own terminal trigger statuses.');

$conditionQuery = new ConditionOrderListQuery(
    kind: ConditionOrderKind::TP_SL,
    lifecycle: ConditionOrderLifecycle::CURRENT,
    page: -1,
    pageSize: 1000,
    keyword: ' tp-sl-current ',
    userType: -1,
    symbol: ' btc ',
    side: ' SELL ',
    reduceOnly: '1',
    createdAtStart: ' 2026-08-01 00:00:00 ',
    createdAtEndExclusive: ' 2026-08-07 00:00:00 '
);
assertSameValue(1, $conditionQuery->page(), 'Condition-order pages should fail back to the first page.');
assertSameValue(100, $conditionQuery->pageSize(), 'Condition-order page size should be capped.');
assertSameValue('tp-sl-current', $conditionQuery->keyword(), 'Condition-order keywords should be trimmed.');
assertSameValue(null, $conditionQuery->userType(), 'Unsupported condition-order user types should be ignored.');
assertSameValue('BTC', $conditionQuery->symbol(), 'Condition-order symbols should be normalized.');
assertSameValue('sell', $conditionQuery->side(), 'Condition-order sides should be normalized.');
assertSameValue(true, $conditionQuery->reduceOnly(), 'Condition-order booleans should be normalized.');

$conditionService = new ConditionOrderListQueryService($database->getConnection());
$allCurrentConditions = $conditionService->search(
    new ConditionOrderListQuery(
        kind: ConditionOrderKind::ALL,
        lifecycle: ConditionOrderLifecycle::CURRENT
    ),
    new AgentIdsScope([10])
);
assertSameValue(2, $allCurrentConditions->total(), 'The compatibility preset should preserve the unfiltered endpoint behavior.');

$currentConditions = $conditionService->search(
    new ConditionOrderListQuery(
        kind: ConditionOrderKind::CONDITION,
        lifecycle: ConditionOrderLifecycle::CURRENT
    ),
    new AgentIdsScope([10])
);
assertSameValue(1, $currentConditions->total(), 'Current condition orders should apply kind, lifecycle, and agent scope.');
assertSameValue('condition-current', $currentConditions->items()[0]['condition_id'], 'The matching current condition order should be returned.');
assertSameValue(9001, $currentConditions->items()[0]['user_id'], 'Condition orders should expose the public user UID.');
assertSameValue(1, $currentConditions->items()[0]['user_type'], 'Current condition orders should use the current user type.');

$currentTpSl = $conditionService->search(
    new ConditionOrderListQuery(
        kind: ConditionOrderKind::TP_SL,
        lifecycle: ConditionOrderLifecycle::CURRENT,
        keyword: 'external-bob'
    ),
    new AgentIdsScope([10])
);
assertSameValue(['tp-sl-current'], array_column($currentTpSl->items(), 'condition_id'), 'TP/SL identity search should include active external bindings.');
assertSameValue('external-bob', $currentTpSl->items()[0]['agent_user_id'], 'TP/SL rows should expose the external user ID.');

$historyTpSl = $conditionService->search(
    new ConditionOrderListQuery(
        kind: ConditionOrderKind::TP_SL,
        lifecycle: ConditionOrderLifecycle::HISTORY
    ),
    new AgentIdsScope([10])
);
assertSameValue(
    '2085983764139225088',
    $historyTpSl->items()[0]['condition_id'],
    'Condition-order IDs larger than JavaScript safe integers must remain exact strings.'
);
assertSameValue(
    'string',
    get_debug_type($historyTpSl->items()[0]['condition_id']),
    'Condition-order IDs must be serialized as strings.'
);

$historyConditions = $conditionService->search(
    new ConditionOrderListQuery(
        kind: ConditionOrderKind::CONDITION,
        lifecycle: ConditionOrderLifecycle::HISTORY,
        userType: 2
    ),
    new AgentIdsScope([10])
);
assertSameValue(['condition-history'], array_column($historyConditions->items(), 'condition_id'), 'History filters should use the order user-type snapshot.');
assertSameValue(2, $historyConditions->items()[0]['user_type'], 'History rows should expose the order user-type snapshot.');
assertSameValue('0.75', $historyConditions->items()[0]['filled_quantity'], 'Generated-order fill quantity should be projected.');

$emptyConditionScope = $conditionService->search(
    new ConditionOrderListQuery(
        kind: ConditionOrderKind::CONDITION,
        lifecycle: ConditionOrderLifecycle::CURRENT
    ),
    new AgentIdsScope([])
);
assertSameValue([], $emptyConditionScope->items(), 'An empty condition-order scope must fail closed.');

$historyOrderQuery = new HistoryOrderListQuery(
    page: -5,
    pageSize: 500,
    keyword: ' external-bob ',
    userType: 9,
    symbol: ' btcusdt ',
    orderType: ' LIMIT ',
    marginMode: 7,
    side: ' SELL ',
    leverage: -3,
    reduceOnly: false,
    makerOnly: true,
    orderStatuses: [3, '3', 6, 9],
    createdAtStart: ' 2026-08-01 00:00:00 ',
    createdAtEnd: ' 2026-08-02 23:59:59 ',
    orderBy: 'unsupported',
    orderDirection: 'ASC'
);
assertSameValue(1, $historyOrderQuery->page(), 'History-order pages should fail back to the first page.');
assertSameValue(100, $historyOrderQuery->pageSize(), 'History-order page size should be capped.');
assertSameValue('external-bob', $historyOrderQuery->keyword(), 'History-order keywords should be trimmed.');
assertSameValue(null, $historyOrderQuery->userType(), 'Unsupported history-order user types should be ignored.');
assertSameValue('BTCUSDT', $historyOrderQuery->symbol(), 'History-order symbols should be normalized.');
assertSameValue('limit', $historyOrderQuery->orderType(), 'History-order types should be normalized.');
assertSameValue(null, $historyOrderQuery->marginMode(), 'Unsupported history-order margin modes should be ignored.');
assertSameValue('sell', $historyOrderQuery->side(), 'History-order sides should be normalized.');
assertSameValue(null, $historyOrderQuery->leverage(), 'Invalid history-order leverage should be ignored.');
assertSameValue([3, 6], $historyOrderQuery->orderStatuses(), 'History-order statuses should be unique terminal values.');
assertSameValue('created_at', $historyOrderQuery->orderBy(), 'Unsupported history-order sorting should use creation time.');
assertSameValue('asc', $historyOrderQuery->orderDirection(), 'History-order sort direction should be normalized.');
assertSameValue(false, $historyOrderQuery->sourceFilters()['reduce_only'], 'History-order boolean false must remain a valid source filter.');
assertSameValue([1, 2], $historyOrderQuery->sourceFilters([2, '1', 0, 2])['current_user_type_user_ids'], 'History-order fallback IDs should be normalized and unique.');

$historyOrderContext = new HistoryOrderUserContextService($database->getConnection());
assertSameValue(
    [1, 2, 3],
    $historyOrderContext->scopedPlatformUserIds(new AgentIdsScope([10])),
    'History orders should resolve platform users inside the supplied scope.'
);
assertSameValue(
    [2],
    $historyOrderContext->platformUserIdsForType(new AgentIdsScope([10]), 2),
    'History-order current user-type fallbacks should remain scope-safe.'
);
assertSameValue(
    [2],
    $historyOrderContext->matchingPlatformUserIds('external-bob', new AgentIdsScope([10])),
    'History-order identity search should include active external bindings.'
);
assertSameValue(
    [],
    $historyOrderContext->scopedPlatformUserIds(new AgentIdsScope([])),
    'An empty history-order scope must fail closed.'
);

$hydratedHistoryOrders = $historyOrderContext->hydrateRows([
    ['order_id' => 8101, 'platform_user_id' => 1, 'user_type' => 2, 'symbol' => 'BTCUSDT'],
    ['order_id' => 8102, 'platform_user_id' => 2, 'user_type' => 0, 'symbol' => 'ETHUSDT'],
    ['order_id' => 8103, 'platform_user_id' => 4, 'user_type' => 1, 'symbol' => 'BNBUSDT'],
], new AgentIdsScope([10]));
assertSameValue([8101, 8102], array_column($hydratedHistoryOrders, 'order_id'), 'History-order hydration must discard source rows outside the supplied scope.');
assertSameValue(1, $hydratedHistoryOrders[0]['platform_user_id'], 'History-order hydration should preserve the internal platform user ID.');
assertSameValue(9001, $hydratedHistoryOrders[0]['user_id'], 'History-order hydration should expose the public user UID.');
assertSameValue('external-alice', $hydratedHistoryOrders[0]['agent_user_id'], 'History-order hydration should expose the active external binding.');
assertSameValue(2, $hydratedHistoryOrders[0]['user_type'], 'A valid history-order user type snapshot should remain authoritative.');
assertSameValue(2, $hydratedHistoryOrders[1]['user_type'], 'History-order hydration should fall back to the current user type.');

fwrite(STDOUT, "Shared user-list, user-asset, account-change, current-position, history-position, current-order, history-order, liquidation, trade-fill, condition-order, online-user, user-detail, and user-action contract tests passed.\n");
