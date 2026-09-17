<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\SymbolTags\SymbolTagException;
use SixMm\Shared\SymbolTags\SymbolTagQuery;
use SixMm\Shared\SymbolTags\SymbolTagService;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertSymbolTagSame(mixed $expected, mixed $actual, string $message): void
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
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->setAsGlobal();
$connection = $database->getConnection();
$schema = $connection->getSchemaBuilder();

$schema->create('symbol_tag', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('parent_id')->default(0);
    $table->string('tag_name');
    $table->string('tag_code');
    $table->string('tag_name_zh')->default('');
    $table->string('tag_name_en')->default('');
    $table->integer('sort')->default(1000);
    $table->integer('is_enable')->default(1);
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
    $table->dateTime('deleted_at')->nullable();
});
$schema->create('symbol_config_tag', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('symbol_config_id');
    $table->unsignedInteger('symbol_tag_id');
});
$schema->create('agent_symbol_tag', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('agent_id');
    $table->unsignedInteger('parent_id')->default(0);
    $table->string('tag_name');
    $table->string('tag_code');
    $table->string('tag_name_zh')->default('');
    $table->string('tag_name_en')->default('');
    $table->integer('sort')->default(1000);
    $table->integer('is_enable')->default(1);
    $table->dateTime('created_at')->nullable();
    $table->dateTime('updated_at')->nullable();
    $table->dateTime('deleted_at')->nullable();
});
$schema->create('agent_symbol_config_tag', static function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedBigInteger('agent_id');
    $table->unsignedInteger('agent_symbol_config_id');
    $table->unsignedInteger('agent_symbol_tag_id');
});

$connection->table('symbol_tag')->insert([
    ['id' => 10, 'parent_id' => 0, 'tag_name' => '加密货币', 'tag_code' => 'crypto', 'tag_name_zh' => '加密货币', 'tag_name_en' => 'Crypto', 'sort' => 800, 'is_enable' => 1],
    ['id' => 11, 'parent_id' => 10, 'tag_name' => 'Layer1', 'tag_code' => 'layer1', 'tag_name_zh' => 'Layer1', 'tag_name_en' => 'Layer1', 'sort' => 850, 'is_enable' => 1],
]);
$connection->table('symbol_config_tag')->insert([
    ['symbol_config_id' => 1, 'symbol_tag_id' => 10],
    ['symbol_config_id' => 2, 'symbol_tag_id' => 10],
    ['symbol_config_id' => 3, 'symbol_tag_id' => 11],
]);
$connection->table('agent_symbol_tag')->insert([
    ['id' => 20, 'agent_id' => 7, 'parent_id' => 0, 'tag_name' => '代理标签', 'tag_code' => 'agent_root', 'tag_name_zh' => '代理标签', 'tag_name_en' => 'Agent Root', 'sort' => 900, 'is_enable' => 1],
    ['id' => 21, 'agent_id' => 7, 'parent_id' => 20, 'tag_name' => '代理子标签', 'tag_code' => 'agent_child', 'tag_name_zh' => '代理子标签', 'tag_name_en' => 'Agent Child', 'sort' => 800, 'is_enable' => 1],
    ['id' => 30, 'agent_id' => 8, 'parent_id' => 0, 'tag_name' => '其他代理', 'tag_code' => 'other_agent', 'tag_name_zh' => '其他代理', 'tag_name_en' => 'Other Agent', 'sort' => 999, 'is_enable' => 1],
]);
$connection->table('agent_symbol_config_tag')->insert([
    ['agent_id' => 7, 'agent_symbol_config_id' => 101, 'agent_symbol_tag_id' => 20],
    ['agent_id' => 7, 'agent_symbol_config_id' => 102, 'agent_symbol_tag_id' => 20],
    ['agent_id' => 8, 'agent_symbol_config_id' => 201, 'agent_symbol_tag_id' => 30],
]);

$service = new SymbolTagService($connection);
$result = $service->search(new SymbolTagQuery());
assertSymbolTagSame(2, $result['count'], 'The shared query should list the complete hierarchy.');
assertSymbolTagSame(1, $result['parent_count'], 'The shared query should expose the root count.');
assertSymbolTagSame(2, $result['lists'][0]['symbol_count'], 'Relation counts should be grouped per tag.');

$filtered = $service->search(new SymbolTagQuery('', 10, null));
assertSymbolTagSame(2, $filtered['count'], 'Filtering by a root should retain the root and its children.');

$created = $service->create([
    'parent_id' => 10,
    'tag_name' => '支付',
    'tag_code' => 'PAYMENT',
    'tag_name_zh' => '',
    'tag_name_en' => 'Payment',
    'sort' => 810,
    'is_enable' => 1,
]);
assertSymbolTagSame('payment', $created['tag_code'], 'Codes should be normalized to lowercase.');
assertSymbolTagSame('支付', $created['tag_name_zh'], 'The display name should be the Chinese fallback.');

try {
    $service->create([
        'parent_id' => 0,
        'tag_name' => '重复',
        'tag_code' => 'crypto',
        'sort' => 1,
        'is_enable' => 1,
    ]);
    throw new RuntimeException('Duplicate codes must be rejected.');
} catch (SymbolTagException $exception) {
    assertSymbolTagSame(SymbolTagException::CODE_EXISTS, $exception->reason(), 'The duplicate error should be stable.');
}

$service->update(10, [
    'parent_id' => 0,
    'tag_name' => '加密货币',
    'tag_code' => 'crypto',
    'tag_name_zh' => '加密货币',
    'tag_name_en' => 'Crypto',
    'sort' => 800,
    'is_enable' => 0,
]);
assertSymbolTagSame(0, (int) $connection->table('symbol_tag')->where('id', 11)->value('is_enable'), 'Hiding a root should hide its children.');

try {
    $service->delete(11);
    throw new RuntimeException('Tags assigned to symbols must not be deleted.');
} catch (SymbolTagException $exception) {
    assertSymbolTagSame(SymbolTagException::IN_USE, $exception->reason(), 'The in-use error should be stable.');
}

$agentService = new SymbolTagService(
    $connection,
    'agent_symbol_tag',
    'agent_symbol_config_tag',
    'agent_symbol_tag_id',
    'agent_symbol_config_id',
    7
);
$agentResult = $agentService->search(new SymbolTagQuery());
assertSymbolTagSame(2, $agentResult['count'], 'Agent tags must be isolated to the configured owner.');
assertSymbolTagSame(2, $agentResult['lists'][0]['symbol_count'], 'Agent relation counts must use agent relation columns.');
$agentCreated = $agentService->create([
    'parent_id' => 20,
    'tag_name' => '代理新增',
    'tag_code' => 'agent_created',
    'tag_name_zh' => '代理新增',
    'tag_name_en' => 'Agent Created',
    'sort' => 700,
    'is_enable' => 1,
]);
assertSymbolTagSame(7, (int) $connection->table('agent_symbol_tag')->where('id', $agentCreated['id'])->value('agent_id'), 'Created tags must inherit the configured owner.');
assertSymbolTagSame(3, $service->search(new SymbolTagQuery())['count'], 'The public tag service must remain independent.');

echo "Symbol tag shared service tests passed.\n";
