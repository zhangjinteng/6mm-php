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

$connection->table('symbol_tag')->insert([
    ['id' => 10, 'parent_id' => 0, 'tag_name' => '加密货币', 'tag_code' => 'crypto', 'tag_name_zh' => '加密货币', 'tag_name_en' => 'Crypto', 'sort' => 800, 'is_enable' => 1],
    ['id' => 11, 'parent_id' => 10, 'tag_name' => 'Layer1', 'tag_code' => 'layer1', 'tag_name_zh' => 'Layer1', 'tag_name_en' => 'Layer1', 'sort' => 850, 'is_enable' => 1],
]);
$connection->table('symbol_config_tag')->insert([
    ['symbol_config_id' => 1, 'symbol_tag_id' => 10],
    ['symbol_config_id' => 2, 'symbol_tag_id' => 10],
    ['symbol_config_id' => 3, 'symbol_tag_id' => 11],
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

echo "Symbol tag shared service tests passed.\n";
