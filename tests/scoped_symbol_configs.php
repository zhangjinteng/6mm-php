<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/scoped_symbol_configs.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\SymbolConfigs\ScopedSymbolConfigService;

$capsule=new Capsule();
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db=$capsule->getConnection();
scopedSymbolFixture($db);
$db->table('symbol_config')->insert(['id'=>1,'symbol'=>'BTCUSDT','sort'=>10,'is_active'=>1,'is_visible'=>true,
    'liquidation_fee'=>'0.005','min_quantity'=>'0.001','version'=>4]);
$db->table('symbol_tag')->insert(['id'=>1,'tag_name'=>'Crypto','tag_code'=>'crypto','sort'=>10,'is_enable'=>1]);
$db->table('symbol_tag')->insert(['id'=>2,'tag_name'=>'Hot','tag_code'=>'hot','parent_id'=>1,'sort'=>9,'is_enable'=>1]);
$db->table('symbol_config_tag')->insert(['symbol_config_id'=>1,'symbol_tag_id'=>1,'is_attached'=>true]);
$schemaBefore=json_encode($db->select("SELECT name,sql FROM sqlite_master WHERE type IN ('table','index') ORDER BY name"));
$service=new ScopedSymbolConfigService($db);
$checks=0;
$check=static function (bool $ok,string $message) use (&$checks): void {
    ++$checks; if (!$ok) throw new RuntimeException($message);
};
$effective=fn (int $site,int $agent=0,string $symbol='BTCUSDT')=>$service->effectiveQuery($site,$agent)->where('base.symbol',$symbol)->first();
$tags=fn (int $site,int $agent,object $row)=>$service->tagsFor($site,$agent,[$row])[(int)$row->id]??[];
$service->ensureScope(10,20);
$site=$service->query(10)->first(); $merchant=$service->query(10,20)->first();
$check($site->base_config_id===1 && $merchant->base_config_id===1,'Both base references directly target platform');
$check($merchant->sort===null && $merchant->is_active===null && $merchant->is_visible===null,'Initialization does not set preferences');
$check($merchant->liquidation_fee===null && $merchant->min_quantity===null && $merchant->version===null,'Public fields stay NULL');
$check($db->table('symbol_config_tag')->where('home_site_id','>',0)->count()===0,'No inherited tag rules are copied');
$check($effective(10,20)->liquidation_fee==='0.005' && $effective(10,20)->version===4,'Public values resolve only from platform');
$check((int)$effective(10,20)->is_visible===1 && (int)$effective(10,20)->is_active===1,'Field mapping is trading is_visible / listing is_active');
$check(count($tags(10,20,$merchant))===1,'Absent local tag rules inherit platform');
$service->ensureScope(10,20);
$check($db->table('symbol_config')->count()===3,'Initialization is idempotent');
$service->update(10,0,$site->id,['sort'=>0,'is_visible'=>false,'is_active'=>0]);
$resolved=$effective(10,20);
$check($resolved->sort===0 && (int)$resolved->is_visible===0 && $resolved->is_active===0,'Site explicit zero and false inherit');
$check($service->query(10,20)->first()->sort===null && $service->query(10,20)->first()->is_visible===null,'Inherited changes never materialize');
$service->update(10,20,$merchant->id,['sort'=>3,'is_visible'=>false,'is_active'=>0,'tags'=>[]]);
$service->update(10,0,$site->id,['sort'=>5,'is_visible'=>true,'is_active'=>1]);
$check($effective(10,20)->sort===3 && (int)$effective(10,20)->is_visible===0 && $effective(10,20)->is_active===0,'Merchant off does not fall back to parent on');
$check($tags(10,20,$merchant)===[],'Empty current selection records explicit detaches');
$check($db->table('symbol_config_tag')->where('symbol_config_id',$merchant->id)->where('is_attached',false)->exists(),'Explicit removal uses existing is_attached=false');
$before=json_encode($service->query(10,20)->first());
$db->table('symbol_config')->where('id',1)->update(['liquidation_fee'=>'0.008','min_quantity'=>'0.003','version'=>9,'sort'=>99]);
$check($effective(10,20)->liquidation_fee==='0.008' && $effective(10,20)->version===9,'Platform public update immediately resolves without synchronization');
$check($before===json_encode($service->query(10,20)->first()),'Platform public edit does not write child rows');
$db->table('symbol_config')->insert(['symbol'=>'ETHUSDT','sort'=>2,'is_active'=>1,'is_visible'=>true,'liquidation_fee'=>'0.001']);
$service->ensureScope(10,20);
$check($service->query(10,20)->count()===2 && $effective(10,20)->sort===3,'Partial missing rows repaired without clobbering choices');
$service->ensureScope(11,21);
$foreign=$service->query(11,21)->where('symbol','BTCUSDT')->first();
$service->query(11)->where('base_config_id',1)->update(['deleted_at'=>'2026-11-11 00:00:00']);
$check(count($tags(11,21,$foreign))===1,'Missing/retired site configuration falls back to platform tags');
$service->update(11,21,$foreign->id,['tags'=>[]]);
$check($tags(11,21,$foreign)===[],'Clear selection also detaches platform tags when site configuration is absent');
try { $service->update(10,20,$foreign->id,['sort'=>9]); throw new RuntimeException('Foreign ID accepted'); }
catch (DomainException $e) { $check(true,'Foreign ID rejected'); }
try { $service->update(10,20,$merchant->id,['liquidation_fee'=>'9']); throw new RuntimeException('Public edit accepted'); }
catch (DomainException $e) { $check(true,'Public edits rejected'); }
$service->update(10,20,$merchant->id,['sort'=>null,'tags'=>null]);
$check($service->query(10,20)->where('id',$merchant->id)->value('sort')===null && $effective(10,20)->sort===5,'NULL resets to site inheritance');
$check(count($tags(10,20,$merchant))===1 && $db->table('symbol_config_tag')->where('symbol_config_id',$merchant->id)->count()===0,'Deleting own rules restores tag inheritance');
$custom=$db->table('symbol_tag')->insertGetId(['home_site_id'=>10,'agent_id'=>0,'tag_name'=>'Site only','tag_code'=>'site-only','sort'=>0,'is_enable'=>1]);
$service->update(10,0,$site->id,['tags'=>[$custom]]);
$check(array_map(fn ($tag)=>$tag->tag_code,$tags(10,20,$merchant))===['site-only'],'Custom site tags inherit without copying assignment rules');
$service->ensureScope(10,20);
$check($db->table('symbol_tag')->where('home_site_id',10)->where('agent_id',0)->where('tag_code','site-only')->count()===1,'Initializing does not clone a local custom tag into its own scope');
$service->update(10,20,$merchant->id,['tags'=>[]]);
$localCustom=$db->table('symbol_tag')->where('home_site_id',10)->where('agent_id',20)->where('base_tag_id',$custom)->first();
$check($localCustom && $db->table('symbol_config_tag')->where('symbol_config_id',$merchant->id)->where('symbol_tag_id',$localCustom->id)->where('is_attached',false)->exists(),'Scoped tag IDs detach the same original identity');
$service->update(10,0,$site->id,['tags'=>[]]);
$service->update(10,20,$merchant->id,['tags'=>[]]);
$service->update(10,0,$site->id,['tags'=>[$custom]]);
$check($tags(10,20,$merchant)===[],'Explicit cancellation survives temporary removal by parent');
$db->table('symbol_config_tag')->insert(['symbol_config_id'=>1,'symbol_tag_id'=>2,'is_attached'=>true]);
$check(in_array('hot',array_map(fn ($tag)=>$tag->tag_code,$tags(10,20,$merchant)),true),'Future unrelated parent tags still inherit under per-tag semantics');
$service->update(10,20,$merchant->id,['tags'=>null]);
$check(count($tags(10,20,$merchant))===2,'Tag reset removes all explicit local attach/detach rules');
$foreignTag=$db->table('symbol_tag')->where('home_site_id',11)->where('agent_id',21)->value('id');
try { $service->update(10,20,$merchant->id,['tags'=>[$foreignTag]]); throw new RuntimeException('Foreign tag accepted'); }
catch (DomainException $e) { $check(true,'Foreign tags rejected'); }
$service->update(10,20,$merchant->id,['is_active'=>1,'is_visible'=>true]);
$check((int)$service->query(10,20)->where('id',$merchant->id)->value('is_visible')===1,'Trading writes is_visible');
$service->update(10,20,$merchant->id,['is_visible'=>false]);
$service->update(10,20,$merchant->id,['is_active'=>0]);
$check($service->query(10,20)->where('id',$merchant->id)->value('is_active')===0,'Listing writes is_active');
try { $service->update(10,20,$merchant->id,['is_visible'=>true]); throw new RuntimeException('Trading while delisted accepted'); }
catch (DomainException $e) { $check(true,'Cross-field guard preserved'); }
$check($schemaBefore===json_encode($db->select("SELECT name,sql FROM sqlite_master WHERE type IN ('table','index') ORDER BY name")),'Service does not alter upstream schema');

// Legacy records are imported once, not read as the active runtime configuration.
$db->statement('CREATE TABLE agent_symbol_config (id integer primary key,agent_id integer,symbol_config_id integer,sort integer,trading_status integer,listing_status integer,deleted_at text)');
$db->statement('CREATE TABLE agent_symbol_tag (id integer primary key,agent_id integer,parent_id integer,tag_code text,tag_name text,tag_name_zh text,tag_name_en text,sort integer,is_enable integer,deleted_at text)');
$db->statement('CREATE TABLE agent_symbol_config_tag (agent_id integer,agent_symbol_config_id integer,agent_symbol_tag_id integer)');
$db->statement('CREATE TABLE symbol_tag_translation (id integer primary key autoincrement,symbol_tag_id integer,agent_symbol_tag_id integer,locale text,name text,created_at text,updated_at text,UNIQUE(symbol_tag_id,agent_symbol_tag_id,locale))');
$db->table('agent_symbol_config')->insert(['id'=>77,'agent_id'=>31,'symbol_config_id'=>1,'sort'=>0,'trading_status'=>0,'listing_status'=>0]);
$db->table('agent_symbol_tag')->insert(['id'=>88,'agent_id'=>31,'parent_id'=>0,'tag_code'=>'legacy-only','tag_name'=>'Legacy','tag_name_zh'=>'旧标签','tag_name_en'=>'Legacy','sort'=>1,'is_enable'=>1]);
$db->table('agent_symbol_config_tag')->insert(['agent_id'=>31,'agent_symbol_config_id'=>77,'agent_symbol_tag_id'=>88]);
$db->table('symbol_tag_translation')->insert(['symbol_tag_id'=>0,'agent_symbol_tag_id'=>88,'locale'=>'ja','name'=>'レガシー']);
$service->ensureScope(13,31);
$imported=$service->query(13,31)->where('symbol','BTCUSDT')->first();
$check($imported->sort===0 && (int)$imported->is_visible===0 && $imported->is_active===0,'Legacy trading/listing map to correct existing fields');
$check($imported->liquidation_fee===null && $imported->version===null,'Legacy migration does not populate public fields');
$check(in_array('legacy-only',array_map(fn ($tag)=>$tag->tag_code,$tags(13,31,$imported)),true),'Legacy custom tags preserved');
$localTag=$db->table('symbol_tag')->where('home_site_id',13)->where('agent_id',31)->where('tag_code','legacy-only')->first();
$check($db->table('symbol_tag_translation')->where('symbol_tag_id',$localTag->id)->where('agent_symbol_tag_id',0)->where('locale','ja')->value('name')==='レガシー','Legacy translations retained without legacy_tag_id');
$service->update(13,31,$imported->id,['sort'=>8]);
$service->ensureScope(13,31);
$check($service->query(13,31)->where('id',$imported->id)->value('sort')===8,'Old configuration cannot overwrite an already imported row');
$db->table('symbol_config')->where('id',1)->update(['symbol'=>'BTCNEW']);
$service->syncIdentity(1);
$check($service->query(10,20)->where('id',$merchant->id)->value('symbol')==='BTCNEW' && $service->query(10,20)->where('id',$merchant->id)->value('version')===null,'Platform rename only updates identity, not common parameters');
$check($db->table('agent_symbol_config')->count()===1 && $db->table('agent_symbol_tag')->count()===1,'Legacy source tables remain intact');
echo "Sparse scoped symbol config: {$checks} checks passed\n";
