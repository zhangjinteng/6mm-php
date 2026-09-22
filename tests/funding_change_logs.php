<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\DataScope\AgentIdsScope;
use SixMm\Shared\FundingChangeLogs\FundingChangeLogListQuery;
use SixMm\Shared\FundingChangeLogs\FundingChangeLogListQueryService;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertFundingType(string $filter, string $businessType, string $entryType, FundingChangeLogListQueryService $service): void
{
    $result = $service->search(
        new FundingChangeLogListQuery(changeType: $filter),
        new AgentIdsScope([10]),
        new AgentIdsScope([10])
    );
    $rows = $result->items();
    if (count($rows) !== 1 || $rows[0]['business_type'] !== $businessType || $rows[0]['entry_type'] !== $entryType) {
        throw new RuntimeException("Funding change filter {$filter} did not select {$businessType}:{$entryType}.");
    }
}

$database = new Capsule();
foreach (['asset', 'main'] as $name) {
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], $name);
}
$database->setAsGlobal();

$mainSchema = $database->getConnection('main')->getSchemaBuilder();
$mainSchema->create('users', static function (Blueprint $table): void {
    $table->unsignedBigInteger('user_id')->primary();
    $table->unsignedBigInteger('public_user_id');
    $table->unsignedBigInteger('agent_id');
    $table->string('username')->nullable();
    $table->string('nick_name')->nullable();
    $table->timestamp('deleted_at')->nullable();
});
$mainSchema->create('agent_user_bindings', static function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('agent_id');
    $table->string('agent_user_id');
    $table->unsignedBigInteger('platform_user_id');
    $table->string('bind_status');
});
$database->getConnection('main')->table('users')->insert([
    'user_id' => 100,
    'public_user_id' => 900000100,
    'agent_id' => 10,
    'username' => 'funding-user',
    'nick_name' => null,
    'deleted_at' => null,
]);

$assetSchema = $database->getConnection('asset')->getSchemaBuilder();
$assetSchema->create('funding_accounts', static function (Blueprint $table): void {
    $table->unsignedBigInteger('account_id')->primary();
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('agent_id');
});
$assetSchema->create('funding_assets', static function (Blueprint $table): void {
    $table->unsignedBigInteger('asset_id')->primary();
    $table->string('asset_code');
    $table->unsignedSmallInteger('internal_scale');
});
$assetSchema->create('funding_ledger_entries', static function (Blueprint $table): void {
    $table->unsignedBigInteger('ledger_id')->primary();
    $table->unsignedBigInteger('user_id');
    $table->unsignedBigInteger('account_id');
    $table->unsignedBigInteger('asset_id');
    $table->string('entry_type');
    $table->string('business_type');
    $table->string('business_scope')->nullable();
    $table->unsignedBigInteger('business_id');
    $table->unsignedBigInteger('business_operation_id')->nullable();
    $table->unsignedBigInteger('related_operation_id')->nullable();
    $table->bigInteger('available_delta');
    $table->bigInteger('held_delta');
    $table->bigInteger('available_before');
    $table->bigInteger('available_after');
    $table->bigInteger('held_before');
    $table->bigInteger('held_after');
    $table->timestamp('created_at');
});
$database->getConnection('asset')->table('funding_accounts')->insert(['account_id' => 1, 'user_id' => 100, 'agent_id' => 10]);
$database->getConnection('asset')->table('funding_assets')->insert(['asset_id' => 1, 'asset_code' => 'USDT', 'internal_scale' => 8]);

$types = [
    'deposit' => ['DEPOSIT', 'CREDIT'],
    'stake' => ['SECONDS_BET_STAKE', 'DEBIT'],
    'payout' => ['SECONDS_BET_PAYOUT', 'CREDIT'],
    'refund' => ['SECONDS_BET_REFUND', 'CREDIT'],
    'transfer_hold_created' => ['TRANSFER', 'TRANSFER_HOLD_CREATED'],
    'transfer_hold_released' => ['TRANSFER', 'TRANSFER_HOLD_RELEASED'],
    'transfer_out' => ['TRANSFER', 'TRANSFER_OUT'],
    'transfer_in' => ['TRANSFER', 'TRANSFER_IN'],
    'agent_transfer_in' => ['AGENT_TRANSFER_IN', 'CREDIT'],
    'agent_transfer_out' => ['AGENT_TRANSFER_OUT', 'DEBIT'],
];
$ledgerId = 1;
foreach ($types as [$businessType, $entryType]) {
    $isHoldCreated = $entryType === 'TRANSFER_HOLD_CREATED';
    $database->getConnection('asset')->table('funding_ledger_entries')->insert([
        'ledger_id' => $ledgerId,
        'user_id' => 100,
        'account_id' => 1,
        'asset_id' => 1,
        'entry_type' => $entryType,
        'business_type' => $businessType,
        'business_scope' => null,
        'business_id' => $ledgerId,
        'business_operation_id' => null,
        'related_operation_id' => null,
        'available_delta' => $isHoldCreated ? -250000000 : 100000000,
        'held_delta' => $isHoldCreated ? 250000000 : 0,
        'available_before' => $isHoldCreated ? 500000000 : 0,
        'available_after' => $isHoldCreated ? 250000000 : 100000000,
        'held_before' => 0,
        'held_after' => $isHoldCreated ? 250000000 : 0,
        'created_at' => '2026-09-22 00:00:00',
    ]);
    $ledgerId++;
}

$service = new FundingChangeLogListQueryService(
    $database->getConnection('asset'),
    $database->getConnection('main')
);
foreach ($types as $filter => [$businessType, $entryType]) {
    assertFundingType($filter, $businessType, $entryType, $service);
}

$holdResult = $service->search(
    new FundingChangeLogListQuery(changeType: 'transfer_hold_created'),
    new AgentIdsScope([10]),
    new AgentIdsScope([10])
);
if (($holdResult->items()[0]['frozen_amount'] ?? null) !== '2.50000000') {
    throw new RuntimeException('Funding hold amount was not converted from atomic units.');
}

fwrite(STDOUT, "Funding change log type filters passed.\n");
