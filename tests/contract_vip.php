<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\ContractVip\ContractVipListService;
use SixMm\Shared\ContractVip\ContractVipProgress;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement('CREATE TABLE users (user_id integer PRIMARY KEY, public_user_id integer, username text, nick_name text, agent_id integer, home_site_id integer, vip_level integer, deleted_at text)');
$db->statement('CREATE TABLE user_daily_volume (user_id integer, trade_date text, volume numeric, updated_at text)');
Carbon::setTestNow('2026-11-16 12:00:00');
foreach ([[1, 10, 0], [2, 10, 1], [3, 20, 3], [4, 10, 0]] as [$id, $agent, $level]) {
    $db->table('users')->insert(['user_id' => $id, 'public_user_id' => 9000 + $id, 'username' => 'user-' . $id,
        'nick_name' => $id === 1 ? 'literal_%' : 'other', 'agent_id' => $agent, 'home_site_id' => 1001, 'vip_level' => $level]);
}
$db->table('user_daily_volume')->insert([
    ['user_id' => 1, 'trade_date' => '2026-11-15', 'volume' => 100, 'updated_at' => '2026-11-16 09:00:00'],
    ['user_id' => 1, 'trade_date' => '2026-09-01', 'volume' => 99999, 'updated_at' => '2026-11-16 11:00:00'],
    ['user_id' => 2, 'trade_date' => '2026-11-14', 'volume' => 200, 'updated_at' => '2026-11-16 10:00:00'],
    ['user_id' => 3, 'trade_date' => '2026-11-15', 'volume' => 999999, 'updated_at' => '2026-11-16 11:00:00'],
]);
$scope = new class implements UserDataScope {
    public function apply(Builder $query, string $agentColumn): void { $query->where($agentColumn, 10); }
};
$service = new ContractVipListService($db);
$thresholds = static fn (array $users) => array_fill_keys(array_column($users, 'user_id'), [1 => '100', 2 => '500']);
$result = $service->search($scope, ['sort_field' => 'volume_30d', 'sort_order' => 'desc', 'page_size' => 1], $thresholds);
$check($result['count'] === 3 && $result['lists'][0]['id'] === 2, 'Scope, aggregation and sorting must precede pagination');
$page = $service->search($scope, ['sort_field' => 'volume_30d', 'sort_order' => 'desc', 'page_size' => 1, 'page_no' => 2], $thresholds);
$check($page['lists'][0]['id'] === 1 && $page['lists'][0]['volume_30d'] === '100', 'Volume window and second page remain stable');
$check($page['lists'][0]['last_calculated_at'] === '2026-11-16 09:00:00', 'Calculation timestamp ignores old volume records');
$check($page['lists'][0]['vip_level_updated_at'] === null, 'VIP timestamp is not fabricated');
$check($service->search($scope, ['vip_level' => '0'], $thresholds)['count'] === 2, 'VIP zero remains a valid filter');
$check($service->search($scope, ['keyword' => '_%'], $thresholds)['count'] === 1, 'Keyword wildcards are literal');
$check($service->search($scope, ['keyword' => '9003'], $thresholds)['count'] === 0, 'Foreign UID cannot bypass scope');
$check($service->search($scope, ['keyword' => '9001'], $thresholds)['count'] === 1, 'Public UID keyword remains supported');
$nullLast = $service->search($scope, ['sort_field' => 'last_calculated_at', 'sort_order' => 'asc'], $thresholds);
$check(array_column($nullLast['lists'], 'id') === [1, 2, 4], 'Null timestamps sort last');
$missing = $service->search($scope, [], static fn () => []);
$check($missing['lists'][0]['progress_percent'] === null, 'Missing thresholds do not imply maximum VIP');
$check(ContractVipProgress::calculate(1, '200', [1 => '100', 2 => '500'])['progress_percent'] === 25.0, 'Progress remains compatible');
$check(ContractVipProgress::calculate(3, '999', [1 => '100', 2 => '500'])['progress_percent'] === 100.0, 'Highest level remains compatible');
$empty = new class implements UserDataScope { public function apply(Builder $q, string $column): void { $q->whereRaw('1=0'); } };
$check($service->search($empty, [], static function () { throw new RuntimeException('Must not load thresholds for an empty scope'); })['count'] === 0, 'Empty scope never becomes global');
$db->enableQueryLog();
$service->search($scope, [], $thresholds);
$check(count(array_filter($db->getQueryLog(), static fn ($q) => !preg_match('/^select\b/i', $q['query']))) === 0, 'Reporting performs only SELECT queries');
Carbon::setTestNow();
echo "contract VIP: {$checks} checks passed\n";
