<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\ContractSources\ContractSourceFilter;
use SixMm\Shared\TradeFills\TradeFillListQuery;
use SixMm\Shared\TradeFills\TradeFillOwnerDirectory;
use SixMm\Shared\TradeFills\TradeFillOwnerQueryService;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;
use SixMm\Shared\TradeFills\TradeFillUserContextService;

require dirname(__DIR__) . '/vendor/autoload.php';
$checks = 0;
function ownerCheck(bool $condition, string $message): void {
    global $checks; ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
$capsule = new Capsule(); $capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:']); $capsule->setAsGlobal();
$db = $capsule->getConnection(); $schema = $db->getSchemaBuilder();
$schema->create('agents', static function (Blueprint $t): void {
    $t->integer('id')->primary(); $t->integer('pid'); $t->integer('home_site_id'); $t->string('agent_name'); $t->string('user_name'); $t->softDeletes();
});
$schema->create('wl_sites', static function (Blueprint $t): void { $t->integer('site_id'); $t->integer('tenant_id'); $t->string('status'); });
$schema->create('wl_tenants', static function (Blueprint $t): void { $t->integer('tenant_id'); $t->string('tenant_name'); });
$schema->create('users', static function (Blueprint $t): void {
    $t->integer('user_id'); $t->integer('public_user_id'); $t->integer('home_site_id'); $t->integer('agent_id'); $t->integer('user_type');
    $t->string('username'); $t->string('nick_name'); $t->softDeletes();
});
$schema->create('agent_user_bindings', static function (Blueprint $t): void {
    $t->integer('platform_user_id'); $t->integer('agent_id'); $t->string('agent_user_id'); $t->string('bind_status');
});
$db->table('agents')->insert([
    ['id'=>10,'pid'=>0,'home_site_id'=>200,'agent_name'=>'Merchant 10','user_name'=>'m10','deleted_at'=>null],
    ['id'=>11,'pid'=>10,'home_site_id'=>200,'agent_name'=>'Child','user_name'=>'child','deleted_at'=>null],
    ['id'=>20,'pid'=>0,'home_site_id'=>300,'agent_name'=>'Merchant 20','user_name'=>'m20','deleted_at'=>null],
]);
$db->table('wl_sites')->insert([
    ['site_id'=>99,'tenant_id'=>1,'status'=>'disabled'], ['site_id'=>101,'tenant_id'=>1,'status'=>'enabled'],
    ['site_id'=>100,'tenant_id'=>1,'status'=>'enabled'], ['site_id'=>300,'tenant_id'=>2,'status'=>'enabled'],
]);
$db->table('wl_tenants')->insert([['tenant_id'=>1,'tenant_name'=>'White 1'],['tenant_id'=>2,'tenant_name'=>'White 2']]);
$directory = new TradeFillOwnerDirectory($db);
ownerCheck($directory->scope(new ContractSourceFilter('platform'))->owners() === [[0,0]], 'Platform is strict zero/zero');
$selectedWhiteSites = $directory->scope(new ContractSourceFilter('white_label', null, 1))->owners();
ownerCheck(count($selectedWhiteSites) === 3 && array_diff([99,100,101],array_column($selectedWhiteSites,0)) === [], 'Total-admin selected white label includes all owned sites, including disabled sites');
ownerCheck($directory->scope(new ContractSourceFilter('merchant', 11))->owners() === [[200,10]], 'Child login/filter resolves main merchant');
ownerCheck($directory->scope(new ContractSourceFilter('merchant', 999))->isEmpty(), 'Missing merchant denies all');
ownerCheck($directory->scope(new ContractSourceFilter('white_label', null, 999))->isEmpty(), 'Missing white label denies all');
ownerCheck($directory->scope(new ContractSourceFilter('merchant'))->predicate() === '(o.home_site_id > 0 AND o.agent_id > 0)', 'Total-admin merchant category filters CK fields directly');
ownerCheck($directory->scope(new ContractSourceFilter('white_label'))->predicate() === '(o.home_site_id > 0 AND o.agent_id = 0)', 'Total-admin white-label category filters CK fields directly');
$allPredicate = '((o.home_site_id > 0 AND o.agent_id > 0) OR (o.home_site_id > 0 AND o.agent_id = 0) OR (o.home_site_id = 0 AND o.agent_id = 0))';
ownerCheck($directory->scope(new ContractSourceFilter())->predicate() === $allPredicate, 'All is exactly the three valid categories, not unrestricted history');
ownerCheck(TradeFillOwnerScope::all()->cacheKey() !== (new TradeFillOwnerScope([]))->cacheKey(), 'All categories and denied cache keys differ');
$db->enableQueryLog(); $db->flushQueryLog();
foreach (['','merchant','white_label','platform'] as $type) $directory->scope(new ContractSourceFilter($type));
ownerCheck($db->getQueryLog() === [], 'Category filters must not consult current merchant/site/user directories');
$db->disableQueryLog();
foreach ([[[-1,0]], [[0,10]], [[100,-1]], [['100',0]]] as $bad) {
    try { new TradeFillOwnerScope($bad); throw new RuntimeException('Invalid owner allowed'); } catch (InvalidArgumentException) { ++$checks; }
}
$sqls = [];
$queryService = new TradeFillOwnerQueryService(static function (string $sql) use (&$sqls): array {
    $sqls[] = $sql; return str_starts_with($sql, 'SELECT count()') ? [['total'=>'7']] : [];
}, 'freedex_history', static fn (string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'");
foreach ([[0,0],[100,0],[200,10]] as $pair) {
    $owner = new TradeFillOwnerScope([$pair]); $q = new TradeFillListQuery(page:2, userType:1, tradeTimeStart:'2026-11-01 00:00:00');
    $queryService->rows($q,$owner); ownerCheck($queryService->count($q,$owner) === 7,'Count result'); $queryService->detail(42,$owner);
    foreach (array_slice($sqls,-3) as $sql) {
        ownerCheck(str_contains($sql,$owner->predicate()),'Rows/count/detail share exact owner');
        ownerCheck(!str_contains($sql,'user_id IN'),'No current-user preloading');
    }
    ownerCheck(str_contains($sqls[count($sqls)-3],'OFFSET 20'),'Scope before pagination');
    ownerCheck(str_contains($sqls[count($sqls)-2],'o.user_type = 1'),'User type comes from CK');
}
foreach (['','merchant','white_label'] as $type) {
    $scope = $directory->scope(new ContractSourceFilter($type));
    $queryService->rows(new TradeFillListQuery(),$scope); $queryService->count(new TradeFillListQuery(),$scope); $queryService->detail(42,$scope);
    foreach (array_slice($sqls,-3) as $sql) ownerCheck(str_contains($sql,$scope->predicate()), 'Total-admin rows/count/detail share the category predicate');
}
$db->statement('CREATE TABLE ck_owner_fixture (home_site_id INTEGER, agent_id INTEGER)');
foreach ([[0,0],[100,0],[101,0],[999,777],[200,11],[null,0],[0,10],[-1,0],[100,-1]] as [$site,$agent]) {
    $db->table('ck_owner_fixture')->insert(['home_site_id'=>$site,'agent_id'=>$agent]);
}
$totals = [];
foreach (['','merchant','white_label','platform'] as $type) {
    $totals[$type] = $db->table('ck_owner_fixture as o')->whereRaw($directory->scope(new ContractSourceFilter($type))->predicate())->count();
}
ownerCheck($totals === [''=>5,'merchant'=>2,'white_label'=>2,'platform'=>1], 'Deleted/unregistered merchant and second site retained; NULL, negative and zero/positive pairs excluded');
ownerCheck($totals[''] === $totals['merchant']+$totals['white_label']+$totals['platform'], 'All total equals the three category totals');
$before = count($sqls); $empty = new TradeFillOwnerScope([]);
$queryService->rows(new TradeFillListQuery(),$empty); $queryService->count(new TradeFillListQuery(),$empty); $queryService->detail(42,$empty);
ownerCheck(count($sqls)===$before,'Empty scope never queries CK');
$queryService->rows(new TradeFillListQuery(keyword:'999', symbol:"BTC' OR 1=1", side:'buy', orderBy:'price'),new TradeFillOwnerScope([[100,0]]),[5]);
ownerCheck(str_contains(end($sqls),'o.user_id IN (5)') && str_contains(end($sqls),'o.order_id = 999'),'Keyword IDs only narrow owner');
ownerCheck(str_contains(end($sqls),"BTC\\' OR 1=1"),'Search strings escaped');
$db->table('users')->insert(['user_id'=>5,'public_user_id'=>9005,'home_site_id'=>999,'agent_id'=>20,'user_type'=>2,
    'username'=>'Moved user','nick_name'=>'Moved','deleted_at'=>'2026-11-01']);
$db->table('agent_user_bindings')->insert(['platform_user_id'=>5,'agent_id'=>10,'agent_user_id'=>'external-5','bind_status'=>'BOUND']);
ownerCheck((new TradeFillUserContextService($db))->matchingHistoricalUserIds('external-5')===[5], 'Historical external IDs still searchable after a user moves');
$rows=(new TradeFillUserContextService($db))->hydrateHistoricalRows([
    ['user_id'=>5,'home_site_id'=>200,'agent_id'=>10,'user_type'=>1], ['user_id'=>999,'home_site_id'=>100,'agent_id'=>0,'user_type'=>1],
    ['user_id'=>6,'home_site_id'=>0,'agent_id'=>0,'user_type'=>1],
]);
ownerCheck(count($rows)===3,'Moved/deleted/missing user must not disappear after pagination');
ownerCheck($rows[0]['agent_id']===10 && $rows[0]['user_type']===1,'Current user cannot override CK owner/type');
ownerCheck($rows[0]['agent_user_id']==='external-5','Binding follows historical merchant');
$directory->appendToRows($rows);
ownerCheck(array_column($rows,'source_type')===['merchant','white_label','platform'],'Source types follow event owners');
ownerCheck(array_column($rows,'source_name')===['Merchant 10','White 1','平台用户'],'Names resolved from owner directories');
echo "Trade-fill owners: {$checks} checks passed\n";
