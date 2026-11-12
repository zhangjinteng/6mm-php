<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\HandlingFees\ScopedHandlingFeeConfigService;
use SixMm\Shared\HandlingFees\FeeLevelBatch;
use SixMm\Shared\HandlingFees\HandlingFeeConfigListQuery;
use SixMm\Shared\HandlingFees\HandlingFeeConfigQueryService;
use SixMm\Shared\HandlingFees\HandlingFeeConfigWriteGuard;

$capsule = new Capsule();
$capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:']);
$db = $capsule->getConnection();
$db->getSchemaBuilder()->create('handling_fee_level_config', function (Blueprint $t): void {
    $t->id(); $t->bigInteger('home_site_id')->default(0); $t->bigInteger('agent_id')->default(0);
    $t->integer('level'); $t->string('level_name');
    $t->decimal('volume_30d',20,2);
    foreach (['maker_fee_rate','taker_fee_rate'] as $f) $t->string($f);
    $t->timestamps(); $t->softDeletes();
});
$db->statement('CREATE UNIQUE INDEX fee_scope_level ON handling_fee_level_config(home_site_id,agent_id,level) WHERE deleted_at IS NULL');
$db->table('handling_fee_level_config')->insert([
    ['level'=>0,'level_name'=>'普通会员','volume_30d'=>'5000','maker_fee_rate'=>'0.0002','taker_fee_rate'=>'0.0005'],
    ['level'=>1,'level_name'=>'VIP1','volume_30d'=>'10000','maker_fee_rate'=>'0.0001','taker_fee_rate'=>'0.0004'],
]);
$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($why); };
$service = new ScopedHandlingFeeConfigService($db);
$platform = $service->query(0)->get()->toJson();
$site = $service->ensureScope(10);
$check(count($site) === 2 && $site[0]['level'] === 0, 'Includes all platform tiers, including zero');
$check($site[0]['maker_fee_rate'] === '0.0002', 'Raw rate copied without percent conversion');
$service->query(10)->where('level',0)->update(['maker_fee_rate'=>'0.0003']);
$service->query(10)->where('level',1)->delete();
$merchant = $service->ensureScope(10,20);
$check($merchant[0]['maker_fee_rate'] === '0.0003' && $merchant[1]['maker_fee_rate'] === '0.0001', 'Merchant prefers site per tier and falls back to platform');
$before = $service->query(10,20)->get()->toJson();
$check($service->ensureScope(10,20) === [] && $before === $service->query(10,20)->get()->toJson(), 'Repeated saves neither overwrite nor touch timestamps');
$service->ensureScope(11,21);
$check($platform === $service->query(0)->get()->toJson(), 'Platform rows unchanged');
$check($service->query(11,21)->where('level',0)->value('maker_fee_rate') === '0.0002', 'Other site isolated');
$service->query(10,20)->where('level',1)->update(['deleted_at'=>'2026-11-12']);
$check(count($service->ensureScope(10,20)) === 1, 'Soft-deleted tier can be repaired without touching remaining tiers');
try { $service->ensureScope(0,20); throw new RuntimeException('Invalid scope accepted'); }
catch (InvalidArgumentException $e) { $check(true, 'Invalid merchant scope rejected'); }

$calls = 0;
$rpc = static function (array $payload) use (&$calls, $check): array {
    ++$calls;
    $check($payload['items'][0]['home_site_id'] === '12' && $payload['items'][0]['agent_id'] === '22', 'TK receives exact owner');
    $check($payload['items'][0]['maker_fee_rate'] === '0.0002', 'TK receives raw rate');
    return ['total_count'=>count($payload['items']), 'success_count'=>count($payload['items']), 'failed_count'=>0,
        'results'=>array_map(static fn ($i) => ['level'=>$i['level'],'home_site_id'=>$i['home_site_id'],'agent_id'=>$i['agent_id'],'reject_code'=>0], $payload['items'])];
};
$service->initialize(12,22,$rpc);
$service->initialize(12,22,$rpc);
$check($calls === 1, 'Existing complete scope sends no repeated TK batch');
foreach (['empty','wrong_scope','rejected','missing'] as $failure) {
    try {
        $service->initialize(13,23,static function (array $payload) use ($failure): array {
            $results = array_map(static fn ($i) => ['level'=>$i['level'],'home_site_id'=>$i['home_site_id'],'agent_id'=>$i['agent_id'],'reject_code'=>0],$payload['items']);
            if ($failure === 'empty') $results=[];
            if ($failure === 'wrong_scope') $results[0]['home_site_id']='99';
            if ($failure === 'rejected') $results[0]['reject_code']=1;
            if ($failure === 'missing') array_pop($results);
            return ['total_count'=>2,'success_count'=>2,'failed_count'=>0,'results'=>$results];
        });
        throw new RuntimeException('Bad TK result accepted');
    } catch (DomainException $e) {
        $check(!$service->query(13,23)->exists(), 'Failed batch rolls back all inserted tiers: '.$failure);
    }
}
$check(FeeLevelBatch::payload([['level'=>0,'home_site_id'=>0,'agent_id'=>0,'maker_fee_rate'=>'0','taker_fee_rate'=>'0.0005']])['items'][0]['home_site_id'] === '', 'Platform wire scope stays empty/zero');
$query=new HandlingFeeConfigQueryService($db);
$check($query->search(new HandlingFeeConfigListQuery())->total()===2,'Platform reads exclude all child scopes');
$check($query->search(new HandlingFeeConfigListQuery(homeSiteId:10))->total()===1,'Site reads do not mix with platform agent zero');
$foreignId=(int)$service->query(11,21)->value('id');
$check($query->detail($foreignId,20,10)===null,'Details reject foreign IDs');
$db->getSchemaBuilder()->create('agents',function (Blueprint $t): void {
    $t->bigInteger('id'); $t->bigInteger('pid')->default(0); $t->bigInteger('home_site_id'); $t->softDeletes();
});
$db->table('agents')->insert([['id'=>20,'pid'=>0,'home_site_id'=>10],['id'=>25,'pid'=>20,'home_site_id'=>10]]);
$check($query->search(new HandlingFeeConfigListQuery(agentId:20))->total()===2,'Legacy merchant caller resolves persisted site');
try { $query->search(new HandlingFeeConfigListQuery(agentId:25)); throw new RuntimeException('Child scope accepted'); }
catch (DomainException $e) { $check(true,'Legacy resolution rejects a subaccount'); }
$check(!(new HandlingFeeConfigWriteGuard())->allows(0,10),'Platform write guard rejects white label agent zero');
$check((new HandlingFeeConfigWriteGuard(20,10))->allows(20,10),'Explicit write guard includes both ownership dimensions');
echo "Scoped handling fees: {$checks} checks passed\n";
