<?php

declare (strict_types=1);
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\DataScope\AgentIdsScope;
use SixMm\Shared\CurrentPositions\CurrentPositionListQuery;
use SixMm\Shared\LiquidationWarnings\LiquidationWarningRulesService;
use SixMm\Shared\LiquidationWarnings\LiquidationWarningListService;
require dirname(__DIR__) . '/vendor/autoload.php';
$db = new Capsule();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$c = $db->getConnection();
$schema = $c->getSchemaBuilder();
$schema->create('source_config', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('home_site_id');
    $t->integer('agent_id');
    $t->string('config_key');
    $t->text('config_value');
    $t->text('description')->nullable();
    $t->timestamp('updated_at')->nullable();
    $t->unique(['home_site_id', 'agent_id', 'config_key']);
});
$schema->create('system_config', function (Blueprint $t) {
    $t->string('config_key');
    $t->text('config_value');
});
$schema->create('users', function (Blueprint $t) {
    $t->integer('user_id');
    $t->string('public_user_id');
    $t->string('username');
    $t->string('nick_name')->nullable();
    $t->integer('user_type');
    $t->integer('agent_id');
    $t->softDeletes();
});
$schema->create('user_assets', function (Blueprint $t) {
    $t->increments('ua_id');
    $t->integer('user_id');
    $t->string('wallet_balance');
    $t->string('frozen_balance');
    $t->softDeletes();
});
$schema->create('agent_user_bindings', function (Blueprint $t) {
    $t->integer('agent_id');
    $t->integer('platform_user_id');
    $t->string('agent_user_id');
    $t->string('bind_status');
});
$check = static function ($ok, $message) {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$put = static function ($site, $agent, $entry, $high) use ($c) {
    $c->table('source_config')->insert(['home_site_id' => $site, 'agent_id' => $agent, 'config_key' => 'liquidation_warning_rules', 'config_value' => json_encode(['min_large_position_value' => $entry, 'liquidation_high_risk_threshold' => $high, 'liquidation_warning_notify_enabled' => true])]);
};
$rules = new LiquidationWarningRulesService($c);
$check($rules->get(9, 16)['min_large_position_value'] === 80.0, 'Absent rules use safe legacy defaults.');
$put(0, 0, 0.2, 0.9);
$put(8, 16, 0.3, 0.8);
$check($rules->get(9, 16)['min_large_position_value'] === 20.0, 'Wrong site must not override platform defaults.');
$put(9, 16, 0.4, 0.7);
$check($rules->get(9, 16)['min_large_position_value'] === 40.0, 'Exact merchant/site rules win.');
$check($rules->get()['min_large_position_value'] === 20.0, 'Merchant rules do not change platform defaults.');
$saved = $rules->save(['min_large_position_value' => 35, 'liquidation_high_risk_threshold' => 85, 'liquidation_warning_notify_enabled' => false], 9, 16, function ($entry, $high, $notify, $site, $agent) use ($check) {
    $check($entry === '0.35' && $high === '0.85' && !$notify && $site === 9 && $agent === 16, 'RPC receives scoped fractional ratios.');
});
$check($saved['min_large_position_value'] === 35.0, 'Save returns percentages.');
try {
    $rules->save(['min_large_position_value' => 30, 'liquidation_high_risk_threshold' => 80, 'liquidation_warning_notify_enabled' => true], 9, 16, static function () {
        throw new RuntimeException('rpc down');
    });
    throw new LogicException('Expected RPC failure');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'rpc down', 'RPC failure must propagate.');
}
$check($rules->get(9, 16)['min_large_position_value'] === 35.0, 'Failed RPC rolls back the database save.');
foreach ([[101, 16], [102, 17], [103, 99]] as [$id, $agent]) {
    $c->table('users')->insert(['user_id' => $id, 'public_user_id' => (string) (900000000 + $id), 'username' => 'user' . $id, 'user_type' => 1, 'agent_id' => $agent]);
    $c->table('user_assets')->insert(['user_id' => $id, 'wallet_balance' => '1', 'frozen_balance' => '0']);
}
$c->table('agent_user_bindings')->insert(['agent_id' => 16, 'platform_user_id' => 101, 'agent_user_id' => 'external-101', 'bind_status' => 'BOUND']);
$rpc = static fn($threshold, $limit, $cursor) => [['user_id' => '101', 'symbol' => 'BTCUSDT', 'margin_mode' => 1, 'side' => 1, 'quantity' => '2', 'entry_price' => '50000', 'margin_ratio' => '0.8', 'wallet_balance' => '500', 'margin_balance' => '450', 'liq_price' => '49990', 'unrealized_pnl' => '-50', 'maintenance_margin' => '360'], ['user_id' => '103', 'symbol' => 'BTCUSDT', 'margin_mode' => 1, 'side' => 1, 'margin_ratio' => '0.9']];
$sqls = [];
$select = static function ($sql) use (&$sqls, $check) {
    $sqls[] = $sql;
    $check(!str_contains($sql, '103'), 'Foreign users must not enter the ClickHouse query.');
    $check(str_contains($sql, "(101, 'BTCUSDT', 1, 'long')"), 'Risk joins must include side and margin mode, not merely user/symbol.');
    return str_contains($sql, 'count()') ? [['total' => 1]] : [['id' => '9001', 'position_id' => '9001', 'user_id' => '101', 'symbol' => 'BTCUSDT', 'margin_mode' => 1, 'position_side' => 'long', 'user_type' => 1, 'quantity' => '1', 'entry_price' => '1']];
};
$list = new LiquidationWarningListService($c, $rpc, $select, 'freedex_history', 'UTC');
$page = $list->search(new CurrentPositionListQuery(keyword: 'external-101', symbol: 'btc', marginMode: 1), new AgentIdsScope([16, 17]), 0.35);
$row = $page->items()[0];
$check($page->total() === 1 && $row['user_id'] === '900000101' && $row['agent_user_id'] === 'external-101', 'Public UID and binding must be hydrated within scope.');
$check($row['wallet_balance'] === '500' && $row['quantity'] === '2' && $row['margin_ratio'] === '0.8', 'Realtime risk values must not be overwritten by stale database balances.');
$check(count($sqls) === 2 && str_contains($sqls[1], 'ORDER BY o.position_id DESC'), 'Count and paged query must share filters and safe ordering.');
$empty = $list->search(new CurrentPositionListQuery(), new AgentIdsScope([]), 0.35);
$check($empty->total() === 0 && count($sqls) === 2, 'Empty scope must short circuit.');
$cursorCalls = [];
$paging = new LiquidationWarningListService($c, static function ($t, $limit, $cursor) use (&$cursorCalls) {
    $cursorCalls[] = $cursor;
    return $cursor === 0 ? [['user_id' => 101, 'symbol' => 'BTCUSDT', 'margin_mode' => 1, 'side' => 1], ['user_id' => 102, 'symbol' => 'BTCUSDT', 'margin_mode' => 1, 'side' => 1]] : [['user_id' => 102, 'symbol' => 'BTCUSDT', 'margin_mode' => 1, 'side' => 1]];
}, $select, 'freedex_history', 'UTC', 2);
$paging->search(new CurrentPositionListQuery(), new AgentIdsScope([16, 17]), 0.2);
$check($cursorCalls === [0, 101], 'Full RPC batches must re-read the last user to avoid dropping positions.');
$normalized = LiquidationWarningRulesService::normalize([
    'min_large_position_value' => 0.29,
    'liquidation_high_risk_threshold' => 0.57,
    'liquidation_warning_notify_enabled' => false,
], true);
$check($normalized['min_large_position_value'] === 29.0 && $normalized['liquidation_high_risk_threshold'] === 57.0,
    'Storage ratio conversion must not expose binary arithmetic artifacts.');
$failed = new LiquidationWarningListService($c, static function () {
    throw new RuntimeException('risk rpc unavailable');
}, static function () { throw new LogicException('ClickHouse must not run after a risk RPC failure.'); });
try {
    $failed->search(new CurrentPositionListQuery(), new AgentIdsScope([16]), 0.2);
    throw new LogicException('Expected a failed risk request, not an empty success page.');
} catch (RuntimeException $e) {
    $check($e->getMessage() === 'risk rpc unavailable', 'A failed risk request must not become a misleading zero count.');
}
echo "Liquidation warning tests passed.\n";
