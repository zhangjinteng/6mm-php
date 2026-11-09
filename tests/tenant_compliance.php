<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\TenantCompliance\ComplianceConfigStore;
$container=new Container();
$container->instance('validator',new Factory(new Translator(new ArrayLoader(),'en'),$container));
Facade::setFacadeApplication($container);
$capsule=new Capsule(); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db=$capsule->getConnection();
$db->statement('CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name text, status text)');
$db->statement('CREATE TABLE wl_sites (site_id integer PRIMARY KEY, tenant_id integer, site_name text, status text)');
        $db->getSchemaBuilder()->create('partner_account_profiles', function (Blueprint $t): void {
            $t->bigIncrements('id'); $t->bigInteger('home_site_id'); $t->bigInteger('agent_id')->nullable(); $t->bigInteger('owner_admin_id')->nullable();
            foreach (['legal_entity_name','registration_number','registered_address','support_email','effective_date','contact_email'] as $f) $t->text($f)->nullable();
            $t->bigInteger('created_by')->nullable(); $t->bigInteger('updated_by')->nullable(); $t->timestamps();
        });
        $db->getSchemaBuilder()->create('wl_agreements', function (Blueprint $t): void {
            $t->bigIncrements('id'); $t->string('scope_type'); $t->bigInteger('site_id')->nullable(); $t->string('agreement_type');
            $t->string('locale')->default('en'); $t->string('code')->nullable(); $t->string('name')->nullable(); $t->bigInteger('template_id')->nullable();
            $t->string('template_scope_type')->default('platform'); $t->string('source_mode')->nullable(); $t->string('custom_type')->nullable();
            $t->text('draft_sections')->default('[]'); $t->text('published_sections')->default('[]'); $t->text('draft_url')->nullable(); $t->text('published_url')->nullable();
            $t->bigInteger('row_version')->default(1); $t->bigInteger('published_version')->default(0); $t->boolean('is_enabled')->default(true);
            foreach (['created','updated','published'] as $f) { $t->string($f.'_by_type')->default('system'); $t->bigInteger($f.'_by_id')->nullable(); }
            $t->timestamps(); $t->timestamp('published_at')->nullable();
        });


$db->table('wl_tenants')->insert([['tenant_id'=>1,'tenant_name'=>'Alpha_%','status'=>'enabled'],['tenant_id'=>2,'tenant_name'=>'Other','status'=>'enabled']]);
foreach ([10,11,12,20] as $id) $db->table('wl_sites')->insert(['site_id'=>$id,'tenant_id'=>$id===20?2:1,'site_name'=>'Site'.$id,'status'=>$id===12?'disabled':'enabled']);
foreach (array_keys(ComplianceConfigStore::TYPES) as $type) $db->table('wl_agreements')->insert(['scope_type'=>'platform','agreement_type'=>$type,'draft_sections'=>'[{"title":"PRIVATE DRAFT"}]','updated_at'=>'2026-11-04 00:00:00']);
$platform=new ComplianceConfigStore($db); $tenant=new ComplianceConfigStore($db,true);
require __DIR__.'/fixtures/site_themes.php';
themeFixture($db);
$checks=0; $check=static function(bool $ok) use (&$checks): void {++$checks; if(!$ok) throw new RuntimeException('Compliance check failed #'.$checks);};
$check(array_column($tenant->sites('1'),'site_id')===['10','11']);
$check($platform->listing(['keyword'=>'_%'])['count']===3);
$scope=['tenant_id'=>'1','site_id'=>'10'];
$detail=$tenant->detail($scope);
$check($detail['agreements'][0]['state']==='unpublished' && !str_contains(json_encode($detail),'PRIVATE DRAFT'));
$check($db->table('partner_account_profiles')->count()===0 && $db->table('wl_agreements')->count()===5);
$input=$scope+['expected_revision'=>$detail['revision'],'profile'=>['legal_entity_name'=>'Alpha','registration_number'=>'R-1','registered_address'=>'Private address','support_email'=>'private@example.com','effective_date'=>'2028-02-29'],
'agreements'=>array_map(fn($a)=>['agreement_type'=>$a['agreement_type'],'mode'=>'platform','url'=>'','is_enabled'=>true],$detail['agreements'])];
$input['agreements'][0]['mode']='url'; $input['agreements'][0]['url']='https://legal.example.com/secret-terms';
$audit=[]; $logger=static function($change) use (&$audit): void {$audit[]=$change;};
$saved=$tenant->save($input,77,'tenant',$logger);
$check($saved['changed'] && $saved['detail']['agreements'][0]['state']==='ready');
$p=$db->table('partner_account_profiles')->first();
$check($p->owner_admin_id===null && $p->created_by===null && $p->updated_by===null);
$check($db->table('wl_agreements')->where('scope_type','site')->value('updated_by_type')==='tenant');
$check(!str_contains(json_encode($audit),'Private address') && !str_contains(json_encode($audit),'private@example.com') && !str_contains(json_encode($audit),'secret-terms'));
$input['expected_revision']=$saved['detail']['revision'];
$check(!$tenant->save($input,77,'tenant',$logger)['changed'] && count($audit)===1);
foreach ([['site_id'=>20],['site_id'=>12],['expected_revision'=>str_repeat('0',64)],['published_sections'=>[]],['profile'=>array_replace($input['profile'],['owner_admin_id'=>77])]] as $bad) {
    try {$tenant->save(array_replace($input,$bad),77,'tenant',$logger); throw new RuntimeException('Unsafe input accepted');}
    catch(ValidationException $e){$check(true);}
}
foreach (['http://example.com','javascript:alert(1)','https://u:p@example.com','https://example.com/\\path'] as $url) {
    $bad=$input; $bad['agreements'][0]['url']=$url;
    try {$tenant->save($bad,77,'tenant',$logger); throw new RuntimeException('Unsafe URL accepted');}
    catch(ValidationException $e){$check(true);}
}
$bad=$input; $bad['agreements'][1]=$bad['agreements'][0];
try {$tenant->save($bad,77,'tenant',$logger); throw new RuntimeException('Duplicate type accepted');}
catch(ValidationException $e){$check(true);}
$bad=$input; $bad['agreements'][1]['mode']='content';
try {$tenant->save($bad,77,'tenant',$logger); throw new RuntimeException('Custom body created');}
catch(ValidationException $e){$check(true);}
$bad=$input; $bad['profile']['legal_entity_name']='ROLLBACK';
try {$tenant->save($bad,77,'tenant',static fn()=>throw new RuntimeException('Audit rejected')); throw new RuntimeException('Audit bypassed');}
catch(RuntimeException $e){$check($e->getMessage()==='Audit rejected');}
$check($tenant->detail($scope)['profile']['legal_entity_name']==='Alpha');
$bad['profile']['legal_entity_name']='Platform update';
$saved=$platform->save($bad,42,'platform',$logger);
$check($db->table('partner_account_profiles')->value('updated_by')===42);
$check($tenant->detail(['tenant_id'=>'1','site_id'=>'11'])['profile']['legal_entity_name']==='');
$db->table('wl_sites')->where('site_id',10)->update(['status'=>'disabled']);
try {$tenant->detail($scope); throw new RuntimeException('Disabled site accepted');} catch(ValidationException $e){$check(true);}
$check($platform->detail($scope)['site_id']==='10');
$check($db->table('wl_agreements')->where('scope_type','platform')->sum('published_version')===0);
echo "Tenant compliance: {$checks} checks passed\n";

