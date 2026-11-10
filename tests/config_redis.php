<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/site_themes.php';

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseTransactionsManager;
use SixMm\Shared\TenantConfig\AgreementTemplatePublication;
use SixMm\Shared\TenantConfig\AgreementTemplateReplication;
use SixMm\Shared\TenantConfig\ThemeRedisOutbox;
use SixMm\Shared\TenantConfig\ThemeRedisOutboxSchema;
use SixMm\Shared\TenantConfig\ThemeRedisPublisher;
use SixMm\Shared\TenantConfig\ThemeSnapshotWriter;

$checks=0;
$check=static function (bool $ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('Generic Redis check failed #'.$checks); };
$capsule=new Capsule(); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']); $db=$capsule->getConnection();
$db->setTransactionManager(new DatabaseTransactionsManager());
$db->statement('CREATE TABLE wl_tenants (tenant_id integer primary key, tenant_name text, status text)');
$db->statement('CREATE TABLE wl_sites (site_id integer primary key, tenant_id integer, site_name text, status text)');
$db->statement('CREATE TABLE wl_agreements (id integer primary key, scope_type text, site_id integer, agreement_type text, code text, name text, locale text, is_enabled boolean, published_version integer, draft_sections text, published_sections text)');
themeFixture($db); ThemeRedisOutboxSchema::create($db);
$db->table('wl_tenants')->insert([['tenant_id'=>1,'tenant_name'=>'White label A','status'=>'enabled'],['tenant_id'=>2,'tenant_name'=>'White label B','status'=>'enabled']]);
$db->table('wl_sites')->insert([['site_id'=>10,'tenant_id'=>1,'site_name'=>'Site A','status'=>'enabled'],['site_id'=>20,'tenant_id'=>2,'site_name'=>'Site B','status'=>'enabled']]);
$db->table('partner_account_profiles')->insert([
    ['home_site_id'=>10,'agent_id'=>null,'support_email'=>'a@example.com','legal_entity_name'=>'Company A'],
    ['home_site_id'=>10,'agent_id'=>5,'support_email'=>'merchant@example.com','legal_entity_name'=>'Merchant company'],
    ['home_site_id'=>20,'agent_id'=>null,'support_email'=>'b@example.com','legal_entity_name'=>'Company B'],
]);
$sections=static fn (string $name) => json_encode([['key'=>'a','title'=>'Title','body_html'=>'<p>'.$name.' {{WHITE_LABEL_NAME}}</p>']]);
$db->table('wl_agreements')->insert([
    ['id'=>10,'scope_type'=>'platform','site_id'=>null,'agreement_type'=>'terms','code'=>'TPL-LEGAL-001','name'=>'Terms','locale'=>'en','is_enabled'=>true,'published_version'=>1,'published_sections'=>$sections('PUBLISHED'),'draft_sections'=>$sections('DRAFT-SECRET')],
    ['id'=>11,'scope_type'=>'platform','site_id'=>null,'agreement_type'=>'terms','code'=>'TPL-LEGAL-CN','name'=>'条款','locale'=>'zh-CN','is_enabled'=>true,'published_version'=>1,'published_sections'=>$sections('中文已发布'),'draft_sections'=>'[]'],
    ['id'=>12,'scope_type'=>'platform','site_id'=>null,'agreement_type'=>'privacy','code'=>'TPL-LEGAL-002','name'=>'Privacy','locale'=>'en','is_enabled'=>true,'published_version'=>0,'published_sections'=>'[]','draft_sections'=>$sections('UNPUBLISHED')],
    ['id'=>13,'scope_type'=>'site','site_id'=>20,'agreement_type'=>'terms','code'=>null,'name'=>null,'locale'=>'en','is_enabled'=>true,'published_version'=>1,'published_sections'=>$sections('PRIVATE-SITE-B'),'draft_sections'=>'[]'],
]);
$table=fn ($name)=>$db->table($name);
$redis=new class($db) {
    public array $hashes=[]; public bool $fail=false; public int $calls=0;
    public function __construct(private $db) {}
    public function eval(string $script,int $n,string $key,string ...$values): int {
        if ($this->db->transactionLevel()!==0) throw new LogicException('Uncommitted Redis IO');
        ++$this->calls; if ($this->fail) throw new RuntimeException('accidental-secret-payload');
        $this->hashes[$key]=array_combine(['id','config_version','schema_version','payload','payload_sha256','previous_config_version'],array_slice($values,0,6));
        return 1;
    }
};
$publisher=new ThemeRedisPublisher(fn ()=>$redis);
$outbox=new ThemeRedisOutbox($publisher);
Container::getInstance()->instance(AgreementTemplateReplication::class,$outbox);
$writer=new ThemeSnapshotWriter($table,$outbox);
$db->transaction(fn ()=>$writer->sync(1,10,7,'test:7','theme'));
$db->transaction(function () use ($table,$redis,$check): void {
    AgreementTemplatePublication::sync($table,10); $check($redis->calls===1);
});
$theme='wl:site:config:theme:10'; $template='wl:config:agreement-template:10:en';
$check(isset($redis->hashes[$theme],$redis->hashes[$template]));
$check($db->table(ThemeRedisOutbox::TABLE)->where('resource_id',10)->count()===2);
$entity=json_decode($redis->hashes[$theme]['payload'],true)['config']['legal']['entity'];
$check($entity['whiteLabelName']==='White label A' && $entity['name']==='Company A');
$check(!str_contains($redis->hashes[$theme]['payload'],'Company B') && !str_contains($redis->hashes[$theme]['payload'],'Merchant company'));
$payload=json_decode($redis->hashes[$template]['payload'],true);
$check($payload['templateId']==='10' && $payload['publishedVersion']==='1' && $payload['locale']==='en');
$check(str_contains($payload['sections'][0]['body_html'],'{{WHITE_LABEL_NAME}}'));
$check(!str_contains(json_encode($payload),'DRAFT-SECRET') && !str_contains(json_encode($payload),'White label A') && !str_contains(json_encode($payload),'PRIVATE-SITE-B'));
$job=fn ()=>$db->table(ThemeRedisOutbox::TABLE)->where('resource_type','agreement_template')->where('resource_id',10)->sole();
$first=$job();
$check($first->tenant_id===null && $first->site_id===null && $first->schema_version===1);
$check($first->payload===$redis->hashes[$template]['payload'] && hash('sha256',$first->payload)===$first->payload_sha256);
$db->transaction(fn ()=>AgreementTemplatePublication::sync($table,10));
$check($job()->config_version===$first->config_version && $job()->previous_config_version===null);
$db->transaction(fn ()=>$outbox->enqueueTemplate($table,11));
$check(isset($redis->hashes['wl:config:agreement-template:11:zh-CN']));
foreach ([12,13] as $invalid) {
    try { $db->transaction(fn ()=>$outbox->enqueueTemplate($table,$invalid)); throw new LogicException('Invalid template accepted'); }
    catch (RuntimeException $e) { $check(true); }
}
$check($db->table(ThemeRedisOutbox::TABLE)->count()===3);
$redis->fail=true;
$db->transaction(function () use ($db,$outbox,$table,$sections): void {
    $db->table('wl_agreements')->where('id',10)->update(['published_version'=>2,'published_sections'=>$sections('FROZEN-V2')]);
    $outbox->enqueueTemplate($table,10);
});
$frozen=$job();
$check($frozen->synced_version===null && $frozen->attempts===1 && $frozen->config_version>$first->config_version);
$check($frozen->previous_config_version===$first->config_version && str_contains($frozen->payload,'FROZEN-V2'));
// Current source/draft changes are NOT what a delivery retry is allowed to read.
$db->table('wl_agreements')->where('id',10)->update(['draft_sections'=>$sections('NEWER-DRAFT'),'published_sections'=>$sections('SOURCE-CHANGED-LATER')]);
$redis->fail=false; $db->table(ThemeRedisOutbox::TABLE)->where('resource_type','agreement_template')->update(['next_attempt_at'=>CarbonImmutable::now('UTC')->subSecond()]);
$check($outbox->drain($table,100,10)['processed']===0); // --site never leaks into platform resources
$check($outbox->drain($table,100,null,'agreement_template',10,'en')['synced']===1);
$check($redis->hashes[$template]['payload']===$frozen->payload && !str_contains($redis->hashes[$template]['payload'],'NEWER-DRAFT'));
$check($job()->synced_version===$frozen->config_version);
$before=$job(); $calls=$redis->calls;
$db->statement("CREATE TRIGGER deny_template_outbox BEFORE UPDATE ON wl_site_theme_redis_outbox WHEN NEW.resource_type='agreement_template' BEGIN SELECT RAISE(ABORT,'fixture'); END");
try { $db->transaction(function () use ($db,$outbox,$table,$sections): void {
    $db->table('wl_agreements')->where('id',10)->update(['published_version'=>3,'published_sections'=>$sections('ROLLBACK')]);
    $outbox->enqueueTemplate($table,10);
}); throw new LogicException('Outbox failure swallowed'); } catch (\Illuminate\Database\QueryException $e) { $check(true); }
$db->statement('DROP TRIGGER deny_template_outbox');
$check($db->table('wl_agreements')->where('id',10)->value('published_version')===2 && $job()->payload===$before->payload && $redis->calls===$calls);
try { $db->transaction(function () use ($db,$outbox,$table,$sections): void {
    $db->transaction(function () use ($db,$outbox,$table,$sections): void {
        $db->table('wl_agreements')->where('id',10)->update(['published_version'=>3,'published_sections'=>$sections('OUTER-ROLLBACK')]);
        $outbox->enqueueTemplate($table,10);
    });
    throw new RuntimeException('outer rollback');
}); } catch (RuntimeException $e) { $check($e->getMessage()==='outer rollback'); }
$check($redis->calls===$calls && $job()->payload===$before->payload);
$check($outbox->rebuildTemplates($table,100,null,0,'en')===[10]);
$check($outbox->drain($table,100,null,'agreement_template')['synced']===1);
$check(!str_contains($redis->hashes[$template]['payload'],'NEWER-DRAFT'));
$check($outbox->rebuildTemplates($table,100,null,10)===[11]);
$check((new ThemeRedisPublisher(fn ()=>$redis,'custom:theme','custom:template'))->templateKey(10,'en')==='custom:template:10:en');
echo "Generic configuration Redis: {$checks} checks passed\n";
