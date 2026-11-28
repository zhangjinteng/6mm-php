<?php
declare(strict_types=1);

use Illuminate\Database\SQLiteConnection;
use SixMm\Shared\TenantBrand\{BindingDns,DomainBindings,DomainConfigException,DomainDns};

require dirname(__DIR__).'/vendor/autoload.php';
function check(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function denied(callable $f): void { try {$f();} catch(DomainConfigException){return;} throw new RuntimeException('Expected rejection'); }
$db=new SQLiteConnection(new PDO('sqlite::memory:'),':memory:','',['driver'=>'sqlite']);
foreach ([
    'create table wl_sites(site_id integer,tenant_id integer,status text)',
    'create table wl_site_domains(site_id integer,domain text unique,is_primary integer)',
    'create table wl_site_config_snapshots(site_id integer, config_version integer)',
    'create table wl_domain_bindings(binding_id text primary key,tenant_id integer,site_id integer,domain text,dns_zone text,
        ownership_token text,cloudfront_tenant_id text,status text,actor_type text,operator_id integer,attempts integer default 0,
        next_check_at text,lease_id text,last_error text,upstream_details text,created_at text,updated_at text)',
    "create unique index live_domain on wl_domain_bindings(domain) where status <> 'deleted'",
    "insert into wl_sites values(10,1,'enabled'),(20,2,'enabled')",
    "insert into wl_site_domains values(10,'old.example',1),(20,'elsewhere.example',1)",
    'insert into wl_site_config_snapshots values(10,8),(20,3)',
] as $sql) $db->statement($sql);
$dns=new class implements BindingDns {
    public bool $ready=false;
    public function zone(string $d):string {return 'example.com';}
    public function records(object $b):array {return [['type'=>'TXT']];}
    public function verify(object $b):void {if (!$this->ready) throw new DomainConfigException('missing TXT');}
};
$p=new DomainBindings($db,$dns,'platform',''); $w=new DomainBindings($db,$dns,'white_label','');
$domain='shop.example.com'; $rows=$db->table('wl_domain_bindings');
$pub=function($b,$add) use($p,$db) {
    $input=$p->publishInput($b,$add);
    $db->table('wl_site_domains')->where('site_id',$b->site_id)->delete();
    foreach ($input['domains'] as $row) $db->table('wl_site_domains')->insert($row+['site_id'=>$b->site_id]);
};
$never=fn()=>throw new RuntimeException('unexpected invocation');
check($p->listing(1,10)===[],'no legacy markers');
denied(fn()=>$w->listing(1,20)); denied(fn()=>$p->prepare(1,10,9,'elsewhere.example'));
$w->prepare(1,10,9,'Shop.Example.com.'); $p->prepare(1,10,9,$domain);
$id=$rows->value('binding_id'); check($rows->count()===1,'dedup across apps'); denied(fn()=>$p->prepare(2,20,8,$domain));
denied(fn()=>$p->verify(1,10,9,$domain)); check($p->sweep($never,$never,$never)===0,'no AWS before proof');
denied(fn()=>$db->transaction(fn()=>$p->guardSave(['tenant_id'=>1,'site_id'=>10,'domains'=>[['domain'=>$domain,'is_primary'=>true]]])));
$dns->ready=true; $w->verify(1,10,9,$domain);
$w->sweep(fn()=>throw new RuntimeException('timeout'),$pub,static fn()=>null);
$due=fn()=>$db->table('wl_domain_bindings')->where('binding_id',$id)->update(['next_check_at'=>'2000-01-01']);
$due(); $w->sweep(function($action,$b)use($id){check($action==='createDomain' && $b->binding_id===$id,'replay same id');
    return ['tenantId'=>'aws-7','status'=>'deploying'];},$pub,$never);
check(!$db->table('wl_site_domains')->where('domain',$domain)->exists(),'not published while deploying');
$due(); $w->sweep(function($action,$b){check($action==='getDomainStatus' && $b->cloudfront_tenant_id==='aws-7','poll AWS tenant');
    return ['tenantId'=>'aws-7','status'=>'active'];},$pub,$never);
check($db->table('wl_site_domains')->where('site_id',10)->where('is_primary',1)->value('domain')==='old.example','primary unchanged');
check($db->table('wl_site_domains')->where('domain',$domain)->exists(),'published when active');
$p->remove(1,10,8,$domain,$pub);
denied(fn()=>$w->prepare(2,20,9,$domain));
$p->sweep(fn()=>['tenantId'=>'aws-7','status'=>'deleting','retryAfterSeconds'=>30],$pub,$never);
$due();$p->sweep(fn()=>['tenantId'=>'aws-7','status'=>'deleted'],$pub,$never);
$w->prepare(2,20,9,$domain); check($rows->where('site_id',20)->value('binding_id')!==$id,'new id after deleted');
$race='race.example.com';
$w->prepare(1,10,9,$race); $w->verify(1,10,9,$race);
$w->sweep(fn()=>['tenantId'=>'aws-race','status'=>'deploying'],$pub,$never);
$db->table('wl_domain_bindings')->where('domain',$race)->update(['next_check_at'=>'2000-01-01']);
$w->sweep(function()use($p,$pub,$race){
    $p->remove(1,10,8,$race,$pub);
    return ['tenantId'=>'aws-race','status'=>'active'];
},$pub,$never);
check($db->table('wl_domain_bindings')->where('domain',$race)->value('status')==='deleting','late poll cannot undo deletion');
check(!$db->table('wl_site_domains')->where('domain',$race)->exists(),'late poll cannot publish');
$cfg=['cname_target'=>'target.example.net','apex_ready'=>true,'apex_ipv4'=>['1.1.1.1','8.8.8.8','9.9.9.9']];
$b=(object)['domain'=>'brand.co.uk','dns_zone'=>'brand.co.uk','ownership_token'=>'abc'];
check((new DomainDns([]))->records($b)===[],'apex pending');
check((new DomainDns($cfg))->records($b)[1]['name']==='@','apex A records');
$b->domain='shop.brand.co.uk'; check((new DomainDns($cfg))->records($b)[1]['name']==='shop','zone-relative CNAME');
denied(fn()=>DomainDns::normalize('*.example.com')); check(DomainDns::normalize('bücher.example')==='xn--bcher-kva.example','IDNA');
echo "OK: shared binding lifecycle, isolation, retry, publication, deletion, DNS records and legacy preservation\n";
