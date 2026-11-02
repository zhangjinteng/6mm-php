<?php

declare(strict_types=1);

// Included by run.php after its legacy-schema assertions. All writes use in-memory SQLite.
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\ContractSources\ContractSourceFilter;
use SixMm\Shared\ContractSources\HedgingSourceScope;
use SixMm\Shared\Hedging\HedgingOwnerScope;
use SixMm\Shared\Hedging\HedgingSubjectConfigQuery;
use SixMm\Shared\Hedging\HedgingSubjectConfigQueryService;
use SixMm\Shared\Hedging\HedgingSymbolConfigQuery;
use SixMm\Shared\Hedging\HedgingSymbolConfigQueryService;
use SixMm\Shared\Hedging\HedgingExecutionQuery;
use SixMm\Shared\Hedging\HedgingExecutionQueryService;
use SixMm\Shared\Hedging\HedgingMonitorQuery;
use SixMm\Shared\Hedging\HedgingMonitorQueryService;
use SixMm\Shared\DataScope\AllUsersScope;

foreach (['exchange_accounts', 'hedge_configs', 'hedge_monitor_snapshots', 'exposure_snapshots', 'order_plans'] as $tableName) {
    $schema->table($tableName, static function (Blueprint $table): void {
        $table->string('owner_type')->default('merchant');
        $table->unsignedBigInteger('owner_id')->default(0);
    });
    $main->table($tableName)->update(['owner_id' => $main->raw('agent_id')]);
}
$schema->drop('hedging_settings');
$schema->create('hedging_settings', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('agent_id')->default(0);
    $table->string('owner_type');
    $table->unsignedBigInteger('owner_id');
    $table->boolean('enabled')->default(true);
    $table->dateTime('updated_at')->nullable();
    $table->unique(['owner_type', 'owner_id']);
});

$now = '2026-10-31 00:00:00';
foreach ([['merchant', 0, 100], ['tenant', 300, 101], ['tenant', 301, 102]] as [$ownerType, $ownerId, $recordId]) {
    $owner = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'agent_id' => 0];
    $main->table('hedging_settings')->insert($owner + ['updated_at' => $now]);
    $main->table('exchange_accounts')->insert($owner + [
        'id' => $recordId, 'name' => "owner-$ownerId", 'exchange' => 'Binance',
        'metadata' => $ownerId === 301 ? '{}' : '{"connection_status":"connected"}', 'updated_at' => $now,
    ]);
    $main->table('hedge_configs')->insert($owner + [
        'id' => $recordId, 'exchange_account_id' => $recordId, 'symbol' => 'SOLUSDT',
        'target_symbol' => 'SOL/USDT:USDT', 'enabled' => true, 'updated_at' => $now,
    ]);
    $main->table('order_plans')->insert($owner + [
        'id' => $recordId, 'idempotency_key' => "owner-$recordId", 'config_id' => $recordId,
        'exchange_account_id' => $recordId, 'exchange' => 'Binance', 'account_name' => "owner-$ownerId",
        'symbol' => 'SOL/USDT:USDT', 'side' => 'SELL', 'reason' => 'first_trigger',
        'quantity' => 1, 'notional_usdt' => 100, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $main->table('order_executions')->insert([
        'id' => $recordId, 'order_plan_id' => $recordId, 'status' => 'planned',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $main->table('hedge_monitor_snapshots')->insert($owner + [
        'config_id' => $recordId, 'exchange_account_id' => $recordId, 'source' => 'platform',
        'symbol' => 'SOLUSDT', 'switch_status' => 'enabled', 'health_status' => 'ok',
        'action_status' => 'balanced', 'status' => 'balanced', 'net_notional_usdt' => $recordId,
        'calculated_at' => $now, 'updated_at' => $now,
    ]);
}
// The old agent column is not part of an owner's identity after migration.
$main->table('exchange_accounts')->where('id', 101)->update(['agent_id' => 900]);
$main->table('hedge_configs')->where('id', 101)->update(['agent_id' => 901]);
// An unconfigured tenant exposure must not be hidden by another tenant's configured symbol.
$main->table('exposure_snapshots')->insert([
    'owner_type' => 'tenant', 'owner_id' => 302, 'agent_id' => 0, 'source' => 'platform',
    'symbol' => 'SOLUSDT', 'net_quantity' => 1, 'net_notional_usdt' => 103, 'observed_at' => $now, 'updated_at' => $now,
]);

$subjectService = new HedgingSubjectConfigQueryService($main);
$symbolConfigService = new HedgingSymbolConfigQueryService($main);
$executionService = new HedgingExecutionQueryService($main);
$hedgingService = new HedgingMonitorQueryService($main);
$ownerRows = $subjectService->search(new HedgingSubjectConfigQuery(), new AllUsersScope())['lists'];
assertSameValue(3, count($ownerRows), 'Three owners with agent zero remain three subjects.');
$byOwner = [];
foreach ($ownerRows as $row) $byOwner[$row['owner_type'] . ':' . $row['owner_id']] = $row;
assertSameValue('running', $byOwner['tenant:300']['status'], 'Tenant 300 uses only its connected account.');
assertSameValue('account_abnormal', $byOwner['tenant:301']['status'], 'Tenant 301 cannot borrow another owner connection.');
assertSameValue([102], array_column($byOwner['tenant:301']['accounts'], 'id'), 'Subject account aggregation isolates owners.');

foreach ([['merchant', 0, 100], ['tenant', 300, 101], ['tenant', 301, 102]] as [$ownerType, $ownerId, $recordId]) {
    $scope = new HedgingOwnerScope($ownerType, $ownerId);
    $configs = $symbolConfigService->search(new HedgingSymbolConfigQuery(), $scope);
    assertSameValue([$recordId], array_column($configs['lists'], 'config_id'), 'Symbol detail isolates exact owner.');
    $executions = $executionService->search(new HedgingExecutionQuery(), $scope);
    assertSameValue(1, $executions['count'], 'Executions isolate exact owner.');
    assertSameValue([$recordId], array_column($executions['options']['accounts'], 'value'), 'Account options isolate exact owner.');
    $monitor = $hedgingService->search(new HedgingMonitorQuery(), $scope);
    assertSameValue(1, $monitor['count'], 'Monitor isolates exact owner.');
    assertSameValue((string) $recordId, $monitor['summary']['net_exposure_usdt'], 'Monitor summary isolates exact owner.');
}
$unconfigured = $hedgingService->search(new HedgingMonitorQuery(), new HedgingOwnerScope('tenant', 302));
assertSameValue(1, $unconfigured['count'], 'Another tenant configuration must not hide an unconfigured exposure.');
$tenantScope = new HedgingSourceScope(new AllUsersScope(), new ContractSourceFilter('white_label', null, 301));
assertSameValue([301], array_column($subjectService->search(new HedgingSubjectConfigQuery(), $tenantScope)['lists'], 'owner_id'), 'White-label selection uses owner ID, not agent zero.');
$platformScope = new HedgingSourceScope(new AllUsersScope(), new ContractSourceFilter('platform'));
assertSameValue(['merchant'], array_column($subjectService->search(new HedgingSubjectConfigQuery(), $platformScope)['lists'], 'owner_type'), 'Platform selection excludes all tenant owners.');
$sources->appendToSubjects($ownerRows);
$names = [];
foreach ($ownerRows as $row) $names[$row['owner_type'] . ':' . $row['owner_id']] = [$row['source_type'], $row['source_name']];
assertSameValue(['platform', '平台用户'], $names['merchant:0'], 'Platform owner display is explicit.');
assertSameValue(['white_label', 'White label 300'], $names['tenant:300'], 'Tenant owner resolves its own tenant name.');
assertSameValue(['white_label', '-'], $names['tenant:301'], 'Missing tenant name does not fall back to platform.');
$merchantRows = [['owner_type' => 'merchant', 'owner_id' => 7, 'agent_id' => 0]];
$sources->appendToSubjects($merchantRows);
assertSameValue('Merchant 7', $merchantRows[0]['source_name'], 'Merchant display resolves owner_id, never the legacy agent_id.');
// A configured exposure with a different legacy agent must not be counted twice.
$main->table('exposure_snapshots')->insert([
    'owner_type' => 'tenant', 'owner_id' => 300, 'agent_id' => 902, 'source' => 'platform',
    'symbol' => 'SOLUSDT', 'net_quantity' => 1, 'net_notional_usdt' => 101, 'observed_at' => $now, 'updated_at' => $now,
]);
$deduplicated = $hedgingService->search(new HedgingMonitorQuery(), new HedgingOwnerScope('tenant', 300));
assertSameValue(1, $deduplicated['count'], 'Configured exposures deduplicate by owner rather than legacy agent.');
assertSameValue('101', $deduplicated['summary']['net_exposure_usdt'], 'Owner exposure is not double-counted.');
$main->table('hedge_monitor_snapshots')->where('owner_type', 'tenant')->where('owner_id', 301)->update(['config_id' => 101]);
$mismatchedConfig = $hedgingService->search(new HedgingMonitorQuery(symbolEnabled: '1'), new HedgingOwnerScope('tenant', 301));
assertSameValue(0, $mismatchedConfig['count'], 'A monitor cannot borrow another owner symbol switch through a mismatched config ID.');
$main->table('hedging_settings')->insert([
    'owner_type' => 'merchant', 'owner_id' => 300, 'agent_id' => 0, 'updated_at' => $now,
]);
$merchantScope = new HedgingSourceScope(new AllUsersScope(), new ContractSourceFilter('merchant'));
$merchantSubjects = $subjectService->search(new HedgingSubjectConfigQuery(), $merchantScope);
assertSameValue([300], array_column($merchantSubjects['lists'], 'owner_id'), 'Merchant selection excludes platform zero and the tenant with the same ID.');
$sameIdTenant = $subjectService->search(new HedgingSubjectConfigQuery(), new HedgingOwnerScope('tenant', 300));
assertSameValue('tenant', $sameIdTenant['lists'][0]['owner_type'], 'An exact tenant does not select a merchant with the same ID.');
assertSameValue([101], array_column($sameIdTenant['lists'][0]['accounts'], 'id'), 'Same-ID owners do not share accounts.');
fwrite(STDOUT, "Hedging owner-scope regression tests passed.\n");
