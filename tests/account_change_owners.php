<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\AccountChangeLogs\AccountChangeLogListQuery;
use SixMm\Shared\AccountChangeLogs\AccountChangeLogListQueryService;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;

$checks = 0;
function ledgerOwnerCheck(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement('CREATE TABLE users (user_id integer, public_user_id text, agent_id integer, home_site_id integer, user_type integer, username text, nick_name text, deleted_at text)');
$db->statement('CREATE TABLE agent_user_bindings (platform_user_id integer, agent_id integer, agent_user_id text, bind_status text)');
$db->statement('CREATE TABLE user_account_change_log (id integer, user_id integer, home_site_id integer, agent_id integer, user_type integer, profile_version integer, symbol text, asset_type text, amount numeric, wallet_balance_before text, wallet_balance_after text, frozen_balance_before text, frozen_balance_after text, change_type text, reference_id text, description text, created_at text, updated_at text, deleted_at text)');
$db->table('users')->insert(['user_id' => 1, 'public_user_id' => '9001', 'home_site_id' => 999, 'agent_id' => 99, 'user_type' => 2, 'username' => 'moved']);
$db->table('agent_user_bindings')->insert([
    ['platform_user_id' => 1, 'agent_id' => 7, 'agent_user_id' => 'event-binding', 'bind_status' => 'BOUND'],
    ['platform_user_id' => 1, 'agent_id' => 99, 'agent_user_id' => 'current-binding', 'bind_status' => 'BOUND'],
]);
foreach ([[1,0,0], [2,1001,0], [3,1002,0], [4,1001,7], [5,1002,7], [6,1001,8],
    [7,0,7], [8,null,0], [9,1001,null], [10,-1,0], [11,1001,7]] as [$id,$site,$agent]) {
    $db->table('user_account_change_log')->insert([
        'id' => $id, 'user_id' => $id === 11 ? 404 : 1, 'home_site_id' => $site, 'agent_id' => $agent,
        'user_type' => 1, 'symbol' => 'BTCUSDT', 'change_type' => 'funding_fee_settle', 'amount' => $id,
        'created_at' => '2026-11-01 00:00:00',
    ]);
}
$service = new AccountChangeLogListQueryService($db);
$criteria = new AccountChangeLogListQuery(pageSize: 20);
$ids = static fn ($page): array => array_column($page->items(), 'id');
foreach ([
    [TradeFillOwnerScope::all(), [11,6,5,4,3,2,1]],
    [new TradeFillOwnerScope([[0,0]]), [1]],
    [TradeFillOwnerScope::category('white_label'), [3,2]],
    [TradeFillOwnerScope::category('merchant'), [11,6,5,4]],
    [new TradeFillOwnerScope([[1001,0],[1002,0]]), [3,2]],
    [new TradeFillOwnerScope([[1001,0]]), [2]],
    [new TradeFillOwnerScope([[1001,7]]), [11,4]],
    [new TradeFillOwnerScope([]), []],
] as [$owner, $expected]) {
    ledgerOwnerCheck($ids($service->searchByOwner($criteria, $owner)) === $expected, 'Owner scope mismatch');
}
$owner = new TradeFillOwnerScope([[1001,7]]);
$page = $service->searchByOwner($criteria, $owner);
ledgerOwnerCheck($page->items()[1]['agent_user_id'] === 'event-binding', 'Binding followed current owner');
ledgerOwnerCheck($page->items()[1]['home_site_id'] === 1001, 'Missing event site');
ledgerOwnerCheck($page->items()[1]['user_type'] === 1, 'Event user type overwritten');
ledgerOwnerCheck($ids($service->searchByOwner(new AccountChangeLogListQuery(keyword: '9001'), $owner)) === [4], 'UID search changed ownership');
ledgerOwnerCheck($ids($service->searchByOwner(new AccountChangeLogListQuery(userType: 2), $owner)) === [4], 'User type search lost');
ledgerOwnerCheck($ids($service->fundingFeesAtSite($criteria, 1001)) === [2], 'Funding fees followed current users');
foreach (['id', 'amount', 'created_at'] as $sort) {
    foreach (['asc', 'desc'] as $direction) {
        $firstCriteria = new AccountChangeLogListQuery(pageSize: 1, orderBy: $sort, orderDirection: $direction);
        $first = $service->searchByOwner($firstCriteria, $owner);
        $next = $service->searchByOwner(new AccountChangeLogListQuery(pageSize: 1, cursor: $first->nextCursor(), orderBy: $sort, orderDirection: $direction), $owner);
        $previous = $service->searchByOwner(new AccountChangeLogListQuery(pageSize: 1, cursor: $next->previousCursor(), orderBy: $sort, orderDirection: $direction), $owner);
        ledgerOwnerCheck($first->hasMore() && !$next->hasMore() && $ids($previous) === $ids($first), 'Cursor navigation failed');
    }
}
$db->enableQueryLog();
$service->searchByOwner($criteria, $owner);
$queries = $db->getQueryLog();
ledgerOwnerCheck(str_contains($queries[0]['query'], 'user_account_change_log') && !str_contains($queries[0]['query'], 'users'), 'Enumerated users before ledger pagination');
ledgerOwnerCheck(!str_contains($queries[0]['query'], '"logs"."user_id" in'), 'Ledger filtered by current-user population');
$db->table('users')->delete();
ledgerOwnerCheck($ids($service->searchByOwner($criteria, $owner)) === [11,4], 'Deleted users removed historical ownership');
echo "Account-change owners: {$checks} checks passed\n";
