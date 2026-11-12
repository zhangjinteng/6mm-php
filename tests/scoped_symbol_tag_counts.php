<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/scoped_symbol_configs.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\SymbolConfigs\ScopedSymbolConfigService;
use SixMm\Shared\SymbolTags\SymbolTagService;
use SixMm\Shared\SymbolTags\SymbolTagQuery;
use SixMm\Shared\SymbolTags\SymbolTagException;

$capsule=new Capsule();
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db=$capsule->getConnection();
scopedSymbolFixture($db);
$checks=0;
$check=function (bool $ok,string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$db->table('symbol_config')->insert([
    ['id'=>1,'symbol'=>'BTCUSDT','is_active'=>1,'is_visible'=>true],
    ['id'=>2,'symbol'=>'ETHUSDT','is_active'=>1,'is_visible'=>true],
]);
$db->table('symbol_tag')->insert([
    ['id'=>1,'parent_id'=>0,'tag_name'=>'Crypto','tag_code'=>'crypto','sort'=>100,'is_enable'=>1],
    ['id'=>2,'parent_id'=>1,'tag_name'=>'Layer 1','tag_code'=>'layer-1','sort'=>90,'is_enable'=>1],
]);
$db->table('symbol_config_tag')->insert([
    ['symbol_config_id'=>1,'symbol_tag_id'=>1,'is_attached'=>true],
    ['symbol_config_id'=>1,'symbol_tag_id'=>2,'is_attached'=>true],
]);
$configs=new ScopedSymbolConfigService($db);
foreach ([[10,20],[10,21],[11,30]] as [$site,$agent]) $configs->ensureScope($site,$agent);
$before=$db->table('symbol_tag')->count();
$configs->ensureScope(10,20);
$check($db->table('symbol_tag')->count()===$before,'Initialization is idempotent');
$check($db->table('symbol_config_tag')->where('home_site_id','>',0)->count()===0,'Initialization does not copy inherited assignments');
foreach ([[0,0],[10,0],[10,20],[10,21],[11,30]] as [$site,$agent]) {
    $tags=$db->table('symbol_tag')->where('home_site_id',$site)->where('agent_id',$agent)->get()->keyBy('base_tag_id');
    $counts=$configs->tagCounts($site,$agent);
    $check(array_sum($counts)===2,'Inherited assignments counted at every scope');
    if ($site>0) $check((int)$tags[2]->parent_id===(int)$tags[1]->id,'Local child references local parent');
}
$merchant=$configs->query(10,20)->where('symbol','BTCUSDT')->first();
$configs->update(10,20,$merchant->id,['tags'=>[]]);
$check($configs->tagCounts(10,20)===[],'Explicit cancellation removes inherited counts');
$check(array_sum($configs->tagCounts(10,21))===2,'Sibling counts stay isolated');
$configs->update(10,20,$merchant->id,['tags'=>null]);
$localRoot=(int)$db->table('symbol_tag')->where('home_site_id',10)->where('agent_id',20)->where('base_tag_id',1)->value('id');
$service=new SymbolTagService($db,'symbol_tag','symbol_config_tag','symbol_tag_id','symbol_config_id',20,'agent_id',
    ['home_site_id'=>10],fn ()=>$configs->tagCounts(10,20));
$listed=$service->search(new SymbolTagQuery());
$check($listed['lists'][0]['symbol_count']===1,'Manager uses effective counts rather than direct relation counts');
$check($service->detail($localRoot)['symbol_count']===1,'Detail count matches list');
try { $service->delete($localRoot+1); throw new LogicException('Inherited in-use child was deleted'); }
catch (SymbolTagException $e) { $check($e->reason()===SymbolTagException::IN_USE,'Cannot delete an inherited in-use tag'); }
$db->table('symbol_config')->where('id',1)->update(['deleted_at'=>'2026-11-11 00:00:00']);
$check($configs->tagCounts(10,20)===[],'Deleted base contracts do not contribute to scoped counts');
$check($configs->tagCounts(0)===[],'Deleted contracts do not contribute to platform counts');
echo "Scoped symbol tag counts: {$checks} checks passed\n";
