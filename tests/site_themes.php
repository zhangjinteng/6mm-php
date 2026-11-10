<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/site_themes.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\TenantConfig\ThemeSnapshotWriter;

$capsule = new Capsule();
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db = $capsule->getConnection();
$db->statement("CREATE TABLE wl_tenants (tenant_id integer primary key, status text, tenant_name text)");
$db->statement("CREATE TABLE wl_sites (site_id integer primary key, tenant_id integer, site_name text, status text)");
themeFixture($db);
$db->statement('PRAGMA foreign_keys=ON');
$db->table('wl_tenants')->insert([['tenant_id'=>1,'status'=>'enabled','tenant_name'=>'Owning white label'],['tenant_id'=>2,'status'=>'enabled','tenant_name'=>'Foreign white label']]);
$db->table('wl_sites')->insert([['site_id'=>10,'tenant_id'=>1,'site_name'=>'交易所','status'=>'enabled'],
    ['site_id'=>11,'tenant_id'=>1,'site_name'=>'Second','status'=>'enabled'],['site_id'=>20,'tenant_id'=>2,'site_name'=>'Foreign','status'=>'enabled']]);
$db->table('wl_site_domains')->insert(['site_id'=>10,'domain'=>'brand.example','is_primary'=>true,'template_id'=>'template-1','logo_url'=>'https://assets.example/logo.svg']);
foreach (['contract'=>'business','seconds'=>'business','trade_ranking'=>'feature','news'=>'feature'] as $code=>$type) {
    $db->table('wl_site_modules')->insert(['site_id'=>10,'module_code'=>$code,'module_type'=>$type,'enabled'=>true]);
}
$db->table('wl_site_seo_configs')->insert(['site_id'=>10,'indexing_enabled'=>true,
    'translations'=>'{"zh-CN":{"title":"标题","description":"描述"}}','google_verification_token'=>'token','verification_domain'=>'brand.example']);
$db->table('wl_channel_configs')->insert(['tenant_id'=>1,'social_links'=>'{"x":"https://x.com/example","future":"private"}',
    'app_downloads'=>'{"apk":"https://download.example/app.apk"}','customer_service'=>'{"app_key_encrypted":"secret"}']);
$db->table('partner_account_profiles')->insert(['home_site_id'=>10,'agent_id'=>null,'support_email'=>'support@example.com']);
$db->table('partner_account_profiles')->insert(['home_site_id'=>10,'agent_id'=>5,'support_email'=>'merchant@example.com']);
$db->table('wl_agreements')->insert(['site_id'=>10,'scope_type'=>'site','locale'=>'en','agreement_type'=>'terms','source_mode'=>'custom',
    'custom_type'=>'url','is_enabled'=>true,'published_version'=>1,'published_url'=>'https://legal.example/terms']);
$writer = new ThemeSnapshotWriter(fn ($name)=>$db->table($name));
$sync = fn ($tenant=1,$site=10)=>$db->transaction(fn ()=>$writer->sync($tenant,$site,7,'test:7','fixture'));
$checks=0;
$check=static function ($ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('Theme check failed #'.$checks); };
try { $writer->sync(1,10,7,'test:7','fixture'); throw new RuntimeException('No transaction accepted'); }
catch (LogicException $e) { $check(true); }
$first=$sync(); $row=$db->table('wl_site_config_themes')->sole();
$p=json_decode($row->payload,true,512,JSON_THROW_ON_ERROR);
$check($p['siteId']==='10' && $p['name']==='交易所' && $p['bindings'][0]['isPrimary']===true);
$check($p['access']['modules']===['contract'=>'enabled','prediction'=>'enabled']);
$check($p['config']['features']['tradingRanking'] && $p['config']['features']['news'] && !$p['config']['features']['help']);
$check($p['config']['templateId']==='template-1' && $p['config']['brand']['faviconUrl']===null);
$check($p['config']['seo']['homeByLocale']['zh-CN']['title']==='标题' && $p['config']['seo']['googleSiteVerification']==='token');
$check(count($p['config']['channels']['social'])===1 && $p['config']['channels']['downloads']['apk']==='https://download.example/app.apk');
$check($p['config']['supportEmail']==='support@example.com' && !str_contains($row->payload,'secret'));
$check($p['config']['channels']['support'] === ['provider'=>null,'publicWidgetId'=>null]);
$check($p['config']['legal']['entity']['whiteLabelName'] === 'Owning white label');
$check($p['config']['legal']['entity']['whiteLabelName'] !== $p['name']);
$check(!$p['config']['legal']['documents'][0]['usePlatformTemplate'] && $p['config']['legal']['documents'][0]['source']['url']==='https://legal.example/terms');
$check($p['config']['legal']['documents'][1]['usePlatformTemplate'] && $p['config']['legal']['documents'][1]['source']['url']===null);
$check(hash_equals($row->payload_sha256,hash('sha256',$row->payload)) && $row->previous_config_version===null);
$check(!$sync()['changed'] && $db->table('wl_site_config_themes')->count()===1);
$db->table('wl_site_seo_configs')->where('site_id',10)->update([
    'translations'=>'{"homeByLocale":{"zh-CN":{"title":"标题","description":"描述"}},"academyByLocale":{"en":{"title":"Academy","description":"Learn"}}}',
]);
$check(!$sync()['changed'] && $db->table('wl_site_config_themes')->count()===1);
$check($db->table('wl_site_config_themes')->sole()->payload === $row->payload);
$db->table('wl_site_modules')->where('module_code','news')->update(['enabled'=>false]);
$second=$sync();
$check($second['changed'] && $db->table('wl_site_config_themes')->orderByDesc('config_version')->first()->previous_config_version===$row->config_version);
$check($db->table('wl_site_config_themes')->where('config_version',$row->config_version)->value('payload')===$row->payload);
try { $sync(1,20); throw new RuntimeException('Foreign site accepted'); }
catch (RuntimeException $e) { $check($e->getMessage()==='白标站点不存在或不属于该白标'); }
$db->statement("CREATE TRIGGER deny_theme BEFORE INSERT ON wl_site_config_themes BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
try { $db->transaction(function () use ($db,$writer) {
    $db->table('wl_sites')->where('site_id',10)->update(['site_name'=>'Rollback']);
    $writer->sync(1,10,7,'test:7','failure');
}); throw new RuntimeException('Failure swallowed'); } catch (\Illuminate\Database\QueryException $e) { $check(true); }
$check($db->table('wl_sites')->where('site_id',10)->value('site_name')==='交易所');
$db->statement('DROP TRIGGER deny_theme');
$db->table('wl_channel_configs')->where('tenant_id',1)->update(['customer_service'=>json_encode([
    'provider'=>'6mm','status'=>'bound','appKey'=>'fixture-widget-key','app_key_encrypted'=>'must-not-publish'])]);
$sync();
$latestPayload = fn () => json_decode($db->table('wl_site_config_themes')->where('site_id',10)->orderByDesc('config_version')->value('payload'),true);
$check($latestPayload()['config']['channels']['support'] === ['provider'=>null,'publicWidgetId'=>null]);
$db->table('wl_site_modules')->insert(['site_id'=>10,'module_code'=>'customer_service','module_type'=>'business','enabled'=>true]);
$check($sync()['changed']);
$check($latestPayload()['config']['channels']['support'] === ['provider'=>'6mm','publicWidgetId'=>'fixture-widget-key']);
$check(!str_contains(json_encode($latestPayload()), 'must-not-publish'));
$check(!$sync()['changed']);
$db->table('wl_site_modules')->where('module_code','customer_service')->update(['enabled'=>false]);
$check($sync()['changed'] && $latestPayload()['config']['channels']['support'] === ['provider'=>null,'publicWidgetId'=>null]);
$db->table('wl_site_modules')->where('module_code','customer_service')->update(['enabled'=>true]);
foreach ([
    ['provider'=>'other','status'=>'bound','appKey'=>'not-ours'],
    ['provider'=>'6mm','status'=>'pending','appKey'=>'pending-key'],
    ['provider'=>'6mm','status'=>'bound','app_key_encrypted'=>'ciphertext'],
    ['provider'=>'6mm','status'=>'bound','appKey'=>''],
    ['provider'=>'6mm','status'=>'bound','appKey'=>123],
] as $invalid) {
    $db->table('wl_channel_configs')->where('tenant_id',1)->update(['customer_service'=>json_encode($invalid)]);
    $sync();
    $check($latestPayload()['config']['channels']['support'] === ['provider'=>null,'publicWidgetId'=>null]);
}
$db->table('wl_channel_configs')->insert(['tenant_id'=>2,'customer_service'=>'{"provider":"6mm","status":"bound","appKey":"foreign-key"}']);
$sync();
$check(!str_contains(json_encode($latestPayload()),'foreign-key'));
$db->table('wl_site_config_themes')->delete();
$check($sync(1,null)['changed'] && $db->table('wl_site_config_themes')->sole()->site_id===10);
$db->table('wl_site_config_themes')->update(['payload_sha256'=>str_repeat('0',64)]);
try { $sync(); throw new RuntimeException('Corruption ignored'); } catch (RuntimeException $e) { $check($e->getMessage()==='站点主题快照校验失败'); }
echo "Site themes: {$checks} checks passed\n";
