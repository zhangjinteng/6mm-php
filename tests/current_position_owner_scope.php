<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\CurrentPositions\CurrentPositionOwnerScope;
use SixMm\Shared\CurrentPositions\CurrentPositionUserContextService;

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = 0;
function sameOwner(mixed $expected, mixed $actual, string $message): void {
    global $checks; ++$checks;
    if ($expected !== $actual) throw new RuntimeException($message . ': ' . json_encode([$expected, $actual]));
}

sameOwner(['o.home_site_id = 0', 'o.agent_id = 0'], CurrentPositionOwnerScope::platform()->clickHousePredicates(), 'Platform must be exact zero/zero');
sameOwner(['p.home_site_id = 100', 'p.agent_id = 0'], CurrentPositionOwnerScope::whiteLabel(100)->clickHousePredicates('p'), 'White label must be a direct site owner');
sameOwner(['o.home_site_id = 100', 'o.agent_id = 11'], CurrentPositionOwnerScope::merchant(100, 11)->clickHousePredicates(), 'Merchant must match both IDs');
foreach ([fn () => CurrentPositionOwnerScope::whiteLabel(0), fn () => CurrentPositionOwnerScope::whiteLabel(-1),
    fn () => CurrentPositionOwnerScope::merchant(0, 11), fn () => CurrentPositionOwnerScope::merchant(100, 0),
    fn () => CurrentPositionOwnerScope::merchant(100, -1), fn () => CurrentPositionOwnerScope::platform()->clickHousePredicates('o; DROP TABLE users')] as $invalid) {
    try { $invalid(); throw new RuntimeException('Unsafe scope unexpectedly accepted'); }
    catch (InvalidArgumentException) { ++$checks; }
}

$capsule = new Capsule(); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal(); $db = $capsule->getConnection(); $schema = $db->getSchemaBuilder();
$schema->create('users', static function (Blueprint $t): void {
    $t->integer('user_id')->primary(); $t->integer('public_user_id');
    $t->integer('home_site_id')->nullable(); $t->integer('agent_id')->nullable();
    $t->string('username'); $t->string('nick_name'); $t->integer('user_type')->default(1); $t->softDeletes();
});
$schema->create('agent_user_bindings', static function (Blueprint $t): void {
    $t->integer('agent_id'); $t->integer('platform_user_id'); $t->string('agent_user_id'); $t->string('bind_status');
});
$schema->create('user_assets', static function (Blueprint $t): void {
    $t->integer('ua_id')->primary(); $t->integer('user_id'); $t->string('wallet_balance'); $t->string('frozen_balance'); $t->softDeletes();
});
foreach ([[1,0,0],[2,null,null],[3,100,0],[4,100,11],[5,101,0],[6,100,12],[7,200,11],[8,100,0],[9,100,null]] as [$id,$site,$agent]) {
    $db->table('users')->insert(['user_id'=>$id,'public_user_id'=>9000+$id,'home_site_id'=>$site,'agent_id'=>$agent,
        'username'=>'user-'.$id,'nick_name'=>'same','deleted_at'=>$id===8?'2026-11-01':null]);
    $db->table('agent_user_bindings')->insert(['agent_id'=>$agent??0,'platform_user_id'=>$id,'agent_user_id'=>'external-'.$id,'bind_status'=>'BOUND']);
    $db->table('user_assets')->insert(['ua_id'=>$id,'user_id'=>$id,'wallet_balance'=>'12','frozen_balance'=>'1']);
}
$context = new CurrentPositionUserContextService($db);
sameOwner([1,2], $context->scopedPlatformUserIds(CurrentPositionOwnerScope::platform()), 'Platform directory scope must not include a tenant');
sameOwner([3,9], $context->scopedPlatformUserIds(CurrentPositionOwnerScope::whiteLabel(100)), 'Only one site and direct users are allowed');
sameOwner([4], $context->scopedPlatformUserIds(CurrentPositionOwnerScope::merchant(100,11)), 'A same merchant on another site or a child admin is not an owner');
sameOwner([4], $context->matchingPlatformUserIds('external-4',CurrentPositionOwnerScope::merchant(100,11)), 'Merchant keyword lookup stays scoped');
sameOwner([], $context->matchingPlatformUserIds('external-4',CurrentPositionOwnerScope::whiteLabel(100)), 'White label cannot search a merchant user');
$rows=$context->hydrateRows([['id'=>'pos-3','user_id'=>3],['id'=>'pos-4','user_id'=>4]],CurrentPositionOwnerScope::whiteLabel(100));
sameOwner(['pos-3'], array_column($rows,'id'), 'Page hydration rechecks ownership');
sameOwner(9003,$rows[0]['user_id'],'Page hydration keeps the public UID');
sameOwner('12',$rows[0]['wallet_balance'],'Page hydration keeps balances');
echo "Current-position owner scope: {$checks} checks passed\n";
