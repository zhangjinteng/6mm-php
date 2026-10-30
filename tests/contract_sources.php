<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\ContractSources\ContractSourceFilter;
use SixMm\Shared\ContractSources\ContractSourceScope;
use SixMm\Shared\ContractSources\ContractSourceService;
use SixMm\Shared\DataScope\AgentIdsScope;
use SixMm\Shared\DataScope\AllUsersScope;

require dirname(__DIR__) . '/vendor/autoload.php';

function sourceCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$db = $capsule->getConnection();
$schema = $db->getSchemaBuilder();
$schema->create('users', static function (Blueprint $table): void {
    $table->bigInteger('user_id');
    $table->bigInteger('agent_id')->nullable();
    $table->bigInteger('home_site_id')->nullable();
    $table->timestamp('deleted_at')->nullable();
});
$schema->create('agents', static function (Blueprint $table): void {
    $table->bigInteger('id'); $table->bigInteger('home_site_id');
    $table->string('agent_name'); $table->string('user_name');
    $table->timestamp('deleted_at')->nullable();
});
$schema->create('wl_sites', static function (Blueprint $table): void {
    $table->bigInteger('site_id'); $table->bigInteger('tenant_id');
});
$schema->create('wl_tenants', static function (Blueprint $table): void {
    $table->bigInteger('tenant_id'); $table->string('tenant_name');
});
$db->table('users')->insert([
    ['user_id'=>1, 'agent_id'=>0, 'home_site_id'=>null, 'deleted_at'=>null],
    ['user_id'=>2, 'agent_id'=>0, 'home_site_id'=>0, 'deleted_at'=>null],
    ['user_id'=>3, 'agent_id'=>12, 'home_site_id'=>100, 'deleted_at'=>null],
    ['user_id'=>4, 'agent_id'=>11, 'home_site_id'=>200, 'deleted_at'=>null],
    ['user_id'=>5, 'agent_id'=>0, 'home_site_id'=>201, 'deleted_at'=>null],
    ['user_id'=>6, 'agent_id'=>0, 'home_site_id'=>100, 'deleted_at'=>null],
    ['user_id'=>7, 'agent_id'=>12, 'home_site_id'=>0, 'deleted_at'=>null],
    ['user_id'=>8, 'agent_id'=>12, 'home_site_id'=>100, 'deleted_at'=>'2026-10-14'],
    ['user_id'=>9, 'agent_id'=>null, 'home_site_id'=>null, 'deleted_at'=>null],
    ['user_id'=>10, 'agent_id'=>11, 'home_site_id'=>999, 'deleted_at'=>null],
]);
$db->table('agents')->insert([
    ['id'=>12, 'home_site_id'=>100, 'agent_name'=>' Merchant ', 'user_name'=>'merchant'],
    ['id'=>11, 'home_site_id'=>200, 'agent_name'=>'White merchant', 'user_name'=>'white'],
]);
$db->table('wl_sites')->insert([
    ['site_id'=>100, 'tenant_id'=>100],
    ['site_id'=>200, 'tenant_id'=>12],
    ['site_id'=>201, 'tenant_id'=>12],
]);
$db->table('wl_tenants')->insert([
    ['tenant_id'=>100, 'tenant_name'=>'Home'], ['tenant_id'=>12, 'tenant_name'=>' White label '],
]);
$sources = new ContractSourceService($db, 100);
// User-list source categories are exclusive; report attribution is tested separately.
sourceCheck($sources->matchingPlatformUserIds(new ContractSourceFilter('platform')) === [1, 2, 7, 9], 'Platform includes null/zero home sites, including anomalous merchant profiles');
sourceCheck($sources->matchingPlatformUserIds(new ContractSourceFilter('merchant')) === [3], 'Merchant requires the injected home site and a positive user agent ID');
sourceCheck($sources->matchingPlatformUserIds(new ContractSourceFilter('merchant', 12)) === [3], 'Merchant names narrow the category');
sourceCheck($sources->matchingPlatformUserIds(new ContractSourceFilter('white_label', null, 12)) === [4, 5], 'Tenant selection includes all its non-home sites');
sourceCheck($sources->matchingPlatformUserIds(new ContractSourceFilter('white_label')) === [4, 5, 6, 10], 'White label includes direct home-site users and positive non-home sites');
sourceCheck($sources->matchingPlatformUserIds(new ContractSourceFilter('white_label', null, 100)) === [6], 'Home-site direct users resolve to the home-site white label');
$options = $sources->options();
sourceCheck($options['merchants'] === [['value'=>'12', 'label'=>'Merchant']], 'Merchant names use platform home site');
sourceCheck($options['white_labels'] === [['value'=>'12', 'label'=>'White label'], ['value'=>'100', 'label'=>'Home']], 'White label options include the home-site tenant and deduplicate sites');

$rows = array_map(static fn (int $id): array => ['platform_user_id'=>$id, 'user_id'=>999, 'agent_id'=>999, 'user'=>[]], range(1, 10));
$rows[] = ['user_id'=>1];
$db->enableQueryLog(); $db->flushQueryLog();
$sources->appendToRows($rows);
sourceCheck(count($db->getQueryLog()) === 3, 'Enrichment batches queries, never queries per row');
sourceCheck($rows[0]['source_name'] === '平台用户' && $rows[0]['source_id'] === '0', 'Platform display');
sourceCheck($rows[2]['source_type'] === 'merchant' && $rows[2]['source_name'] === 'Merchant', 'Merchant display ignores stale row profile fields');
sourceCheck($rows[3]['source_type'] === 'white_label' && $rows[3]['source_name'] === 'White label', 'Tenant display uses the live user home site');
sourceCheck($rows[5]['source_type'] === 'white_label' && $rows[5]['source_name'] === 'Home', 'Home-site user without an agent resolves to its white label');
sourceCheck($rows[6]['source_type'] === 'platform' && $rows[6]['source_name'] === '异常用户', 'Missing site with positive agent is an anomalous platform user');
sourceCheck($rows[7]['source_type'] === null, 'Deleted profiles remain unknown');
sourceCheck($rows[8]['source_type'] === 'platform' && $rows[8]['source_name'] === '平台用户', 'Null agent and site resolve to platform user');
sourceCheck($rows[9]['source_type'] === 'white_label' && $rows[9]['source_name'] === '-', 'Missing tenant name does not invent an agent name');
sourceCheck($rows[10]['source_type'] === null, 'Never resolve a profile from a display UID');
sourceCheck($rows[3]['user']['source_type'] === $rows[3]['source_type'], 'Nested drawer metadata matches the row');

$query = $db->table('users as users')->whereNull('users.deleted_at');
(new ContractSourceScope(new AllUsersScope(), $sources, new ContractSourceFilter('white_label')))->apply($query, 'users.agent_id');
sourceCheck((clone $query)->count() === 4, 'Source predicate is part of the count');
sourceCheck((clone $query)->orderBy('users.user_id')->offset(1)->limit(1)->pluck('users.user_id')->all() === [5], 'Source predicate runs before paging');
$restricted = $db->table('users as users');
(new ContractSourceScope(new AgentIdsScope([12]), $sources, new ContractSourceFilter('white_label')))->apply($restricted, 'users.agent_id');
sourceCheck($restricted->count() === 0, 'Source filtering cannot widen the authenticated data scope');
$missing = new ContractSourceService($db, 0);
sourceCheck($missing->matchingPlatformUserIds(new ContractSourceFilter('merchant')) === [], 'Missing home site fails closed');
sourceCheck($missing->matchingPlatformUserIds(new ContractSourceFilter('platform')) === [1, 2, 7, 9], 'Platform filtering does not need a configured home site');
foreach ([['invalid'], ['platform', 12], ['white_label', 12], ['merchant', null, 12], ['', 0], ['', null, 12], ['merchant', 12, 12]] as $args) {
    try { new ContractSourceFilter(...$args); throw new RuntimeException('Invalid source filter accepted'); }
    catch (InvalidArgumentException $expected) {}
}
echo "Contract source tests passed.\n";
