<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\DataScope\AgentIdsScope;
use SixMm\Shared\DataScope\AllUsersScope;
use SixMm\Shared\UserMappings\UserMappingListQuery;
use SixMm\Shared\UserMappings\UserMappingListQueryService;

require dirname(__DIR__) . '/vendor/autoload.php';

$database = new Capsule();
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$connection = $database->getConnection();
$schema = $connection->getSchemaBuilder();
$schema->create('users', static function (Blueprint $table): void {
    $table->integer('user_id')->primary();
    $table->integer('agent_id');
    $table->integer('public_user_id');
    $table->string('username');
    $table->string('nick_name')->nullable();
    $table->dateTime('deleted_at')->nullable();
});
$schema->create('agent_user_bindings', static function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('agent_id');
    $table->integer('platform_user_id');
    $table->string('agent_user_id');
    $table->string('bind_status');
    $table->text('extra_info')->nullable();
    $table->dateTime('updated_at');
});
$connection->table('users')->insert([
    ['user_id' => 10, 'agent_id' => 2, 'public_user_id' => 800010, 'username' => 'alice', 'nick_name' => 'Alice', 'deleted_at' => null],
    ['user_id' => 20, 'agent_id' => 3, 'public_user_id' => 800020, 'username' => 'bob', 'nick_name' => null, 'deleted_at' => null],
]);
$connection->table('agent_user_bindings')->insert([
    ['id' => 1, 'agent_id' => 2, 'platform_user_id' => 10, 'agent_user_id' => 'ext-1', 'bind_status' => 'BOUND', 'extra_info' => null, 'updated_at' => '2026-09-27 10:00:00'],
    ['id' => 2, 'agent_id' => 3, 'platform_user_id' => 20, 'agent_user_id' => 'ext-2', 'bind_status' => 'PENDING', 'extra_info' => null, 'updated_at' => '2026-09-28 09:00:00'],
    ['id' => 3, 'agent_id' => 2, 'platform_user_id' => 10, 'agent_user_id' => 'ext-3', 'bind_status' => 'UNBOUND', 'extra_info' => '{"error_type":"SYNC_FAILED"}', 'updated_at' => '2026-09-28 10:00:00'],
    ['id' => 4, 'agent_id' => 2, 'platform_user_id' => 20, 'agent_user_id' => 'ext-4', 'bind_status' => 'BOUND', 'extra_info' => null, 'updated_at' => '2026-09-28 11:00:00'],
]);

$service = new UserMappingListQueryService($connection);
$all = $service->search(new UserMappingListQuery(pageSize: 2), new AllUsersScope());
if ($all->total() !== 4 || array_column($all->items(), 'mapping_id') !== [4, 3]) {
    throw new RuntimeException('Mappings must be counted and paginated in descending ID order.');
}
if ($all->items()[0]['user_uid'] !== null) {
    throw new RuntimeException('A binding must not reveal a user owned by another agent.');
}
$agent = $service->search(new UserMappingListQuery(pageSize: 20), new AgentIdsScope([2]));
if ($agent->total() !== 3 || array_column($agent->items(), 'mapping_id') !== [4, 3, 1]) {
    throw new RuntimeException('The agent scope must restrict bindings before pagination.');
}
$syncError = $service->search(new UserMappingListQuery(exceptionType: 'sync_failed'), new AgentIdsScope([2]));
if ($syncError->total() !== 1 || $syncError->items()[0]['mapping_id'] !== 3) {
    throw new RuntimeException('Exception filters must use the full result set.');
}
$keyword = $service->search(new UserMappingListQuery(keyword: '800010', mappingStatus: 'normal'), new AllUsersScope());
if ($keyword->total() !== 1 || $keyword->items()[0]['mapping_id'] !== 1) {
    throw new RuntimeException('UID and status filters must work together.');
}
$date = $service->search(new UserMappingListQuery(startTime: '2026-09-28 00:00:00', endTimeExclusive: '2026-09-29 00:00:00'), new AllUsersScope());
if ($date->total() !== 3) {
    throw new RuntimeException('Date filters must apply before pagination.');
}
echo "User mapping query tests passed.\n";
