<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\HistoryPositions\HistoryPositionUserContextService;

require dirname(__DIR__) . '/vendor/autoload.php';
$checks = 0;
function historySame(mixed $expected, mixed $actual, string $message): void {
    global $checks; ++$checks;
    if ($expected !== $actual) throw new RuntimeException($message . ': ' . json_encode([$expected, $actual]));
}
$capsule = new Capsule();
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db = $capsule->getConnection();
$db->getSchemaBuilder()->create('users', static function (Blueprint $t): void {
    $t->integer('user_id'); $t->integer('public_user_id'); $t->integer('home_site_id'); $t->integer('agent_id');
    $t->string('username'); $t->string('nick_name'); $t->softDeletes();
});
$db->getSchemaBuilder()->create('agent_user_bindings', static function (Blueprint $t): void {
    $t->integer('agent_id'); $t->integer('platform_user_id'); $t->string('agent_user_id'); $t->string('bind_status');
});
$db->table('users')->insert([
    ['user_id'=>1,'public_user_id'=>9001,'home_site_id'=>2002,'agent_id'=>20,'username'=>'moved','nick_name'=>'A','deleted_at'=>null],
    ['user_id'=>2,'public_user_id'=>9002,'home_site_id'=>1001,'agent_id'=>0,'username'=>'deleted','nick_name'=>'B','deleted_at'=>'2026-11-01'],
    ['user_id'=>9,'public_user_id'=>9009,'home_site_id'=>3003,'agent_id'=>30,'username'=>'not-on-page','nick_name'=>'C','deleted_at'=>null],
]);
$db->table('agent_user_bindings')->insert([
    ['agent_id'=>10,'platform_user_id'=>1,'agent_user_id'=>'old-merchant','bind_status'=>'BOUND'],
    ['agent_id'=>20,'platform_user_id'=>1,'agent_user_id'=>'new-merchant','bind_status'=>'BOUND'],
    ['agent_id'=>0,'platform_user_id'=>2,'agent_user_id'=>'deleted-external','bind_status'=>'LOCKED'],
]);
$context = new HistoryPositionUserContextService($db);
$db->enableQueryLog();
historySame([], $context->hydrateHistoricalRows([]), 'Empty pages need no lookups');
historySame([], $db->getQueryLog(), 'No empty-page SQL');
$rows = $context->hydrateHistoricalRows([
    ['id'=>'11','user_id'=>1,'home_site_id'=>1001,'agent_id'=>10,'user_type'=>1],
    ['id'=>'12','user_id'=>2,'home_site_id'=>1001,'agent_id'=>0,'user_type'=>2],
    ['id'=>'13','user_id'=>3,'home_site_id'=>1001,'agent_id'=>0,'user_type'=>3],
]);
historySame(3,count($rows),'Never drop moved, deleted or missing profiles');
historySame([9001,9002,3],array_column($rows,'user_id'),'Keep public UIDs with internal fallback');
historySame([1001,1001,1001],array_column($rows,'home_site_id'),'CK sites remain authoritative');
historySame([10,0,0],array_column($rows,'agent_id'),'CK merchants remain authoritative');
historySame('old-merchant',$rows[0]['agent_user_id'],'Never leak the new merchant external ID');
historySame('deleted-external',$rows[1]['agent_user_id'],'Deleted profiles still supplement historical identity');
historySame([1,2,3],array_column($rows,'user_type'),'CK historical user type remains authoritative');
historySame(10,$rows[0]['user']['agent_id'],'Nested user keeps historical merchant');
historySame(1001,$rows[0]['user']['home_site_id'],'Nested user keeps historical site');
historySame(2,count($db->getQueryLog()),'Only page identities and bindings are queried');
foreach ($db->getQueryLog() as $query) {
    historySame(false,in_array(9,$query['bindings'],true),'Off-page users are not fetched');
}
historySame([1],$context->matchingHistoricalUserIds('9001',10),'Moved user UID remains searchable');
historySame([2],$context->matchingHistoricalUserIds('9002',0),'Deleted UID remains searchable');
historySame([],$context->matchingHistoricalUserIds('new-merchant',10),'External keyword cannot match another merchant binding');
historySame([1],$context->matchingHistoricalUserIds('old-merchant',10),'Historical owner external keyword works');
echo "History-position ownership: {$checks} checks passed\n";
