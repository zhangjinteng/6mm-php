<?php
declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use SixMm\Shared\TenantConfig\ConfigSnapshotWriter;

/** One durable intent per hostname. No DNS/AWS calls inside a database transaction. */
final class DomainBindings
{
    public function __construct(private ConnectionInterface $db, private BindingDns $dns, private string $actor,
        private string $prefix = 'control_plane.') {}
    private function table(string $table) { return $this->db->table($this->prefix.$table); }
    private function rows() { return $this->table('wl_domain_bindings'); }
    private function live() { return $this->rows()->where('status','<>','deleted'); }
    private function fail(string $message): never { throw new DomainConfigException($message); }
    private function now(int $delay=0): string { return gmdate('Y-m-d H:i:s',time()+$delay).'+00:00'; }
    private function publicationLock(): void { (new ConfigSnapshotWriter($this->table(...),$this->fail(...)))->lock(); }
    private function site(int $tenant,int $site): void
    {
        $actual = $this->table('wl_sites')->where('tenant_id',$tenant)
            ->when($this->actor==='white_label',fn($q)=>$q->where('status','enabled'))->orderBy('site_id')->value('site_id');
        if ($site < 1 || (int)$actual !== $site) $this->fail('无权操作该站点');
    }
    private function scoped(int $tenant,int $site) { return $this->live()->where('tenant_id',$tenant)->where('site_id',$site); }
    public function needsFirstPublication(int $tenant,int $site): bool
    {
        $this->site($tenant,$site);
        return !$this->table('wl_site_config_snapshots')->where('site_id',$site)->exists();
    }
    public function listing(int $tenant,int $site): array
    {
        $this->site($tenant,$site);
        return $this->scoped($tenant,$site)->orderBy('created_at')->get()->map(function($b) {
            $waiting = $b->status === 'waiting_for_dns';
            $records = $waiting ? $this->dns->records($b) : [];
            return ['domain'=>$b->domain,'status'=>$waiting && !$records ? 'waiting_for_platform_ips' : $b->status,
                'dnsRecords'=>$records,'error'=>$b->last_error,'retryable'=>!$b->next_check_at && (int)$b->attempts>=10];
        })->all();
    }

    public function prepare(int $tenant,int $site,int $operator,string $input): void
    {
        $this->site($tenant,$site);
        $domain = DomainDns::normalize($input);
        $zone = $this->dns->zone($domain);
        $this->db->transaction(function() use ($tenant,$site,$operator,$domain,$zone) {
            $this->publicationLock();
            $this->site($tenant,$site);
            if ($this->scoped($tenant,$site)->where('domain',$domain)->exists()) return;
            if ($this->table('wl_site_domains')->where('domain',$domain)->exists() || $this->live()->where('domain',$domain)->exists()) {
                $this->fail('域名已被使用；已上线域名请联系运维迁移');
            }
            $published = $this->table('wl_site_domains')->where('site_id',$site)->pluck('domain')->all();
            if (count($published)+$this->scoped($tenant,$site)->whereNotIn('domain',$published)->count() >= 20) $this->fail('访问域名最多 20 个');
            $this->rows()->insert(['binding_id'=>(string)Str::uuid(),'tenant_id'=>$tenant,'site_id'=>$site,'domain'=>$domain,
                'dns_zone'=>$zone,'ownership_token'=>bin2hex(random_bytes(24)),'status'=>'waiting_for_dns',
                'actor_type'=>$this->actor,'operator_id'=>$operator,'created_at'=>$this->now(),'updated_at'=>$this->now()]);
        });
    }

    public function verify(int $tenant,int $site,int $operator,string $domain): void
    {
        $this->site($tenant,$site);
        $b = $this->scoped($tenant,$site)->where('domain',DomainDns::normalize($domain))->first();
        if (!$b) $this->fail('绑定不存在');
        if ($b->status !== 'waiting_for_dns') return;
        $this->dns->verify($b);
        $this->rows()->where('binding_id',$b->binding_id)->where('status','waiting_for_dns')
            ->update(['status'=>'creating','actor_type'=>$this->actor,'operator_id'=>$operator,'next_check_at'=>$this->now(),'updated_at'=>$this->now()]);
    }

    public function remove(int $tenant,int $site,int $operator,string $domain,callable $publish): void
    {
        $this->db->transaction(function() use ($tenant,$site,$operator,$domain,$publish) {
            $this->publicationLock();
            $this->site($tenant,$site);
            $b = $this->scoped($tenant,$site)->where('domain',DomainDns::normalize($domain))->lockForUpdate()->first();
            if (!$b || $b->status === 'deleting') return;
            if (!$b->cloudfront_tenant_id && $b->status !== 'waiting_for_dns') $this->fail('正在确认创建结果，请稍后再解绑');
            $b->actor_type=$this->actor; $b->operator_id=$operator;
            if ($this->table('wl_site_domains')->where('site_id',$site)->where('domain',$b->domain)->exists()) $publish($b,false);
            $this->rows()->where('binding_id',$b->binding_id)->update(['status'=>$b->cloudfront_tenant_id?'deleting':'deleted',
                'actor_type'=>$this->actor,'operator_id'=>$operator,
                'next_check_at'=>$b->cloudfront_tenant_id?($b->lease_id ? $b->next_check_at : $this->now()):null,
                'lease_id'=>null,'last_error'=>null,'attempts'=>0,'updated_at'=>$this->now()]);
        });
    }

    public function retry(int $tenant,int $site,string $domain): void
    {
        $this->site($tenant,$site);
        $this->scoped($tenant,$site)->where('domain',DomainDns::normalize($domain))->whereNull('next_check_at')
            ->where('attempts','>=',10)->update(['attempts'=>0,'last_error'=>null,'next_check_at'=>$this->now(),'updated_at'=>$this->now()]);
    }

    /** Called inside the existing ordinary brand save transaction, NOT the trusted lifecycle publisher. */
    public function guardSave(array $input): void
    {
        $this->publicationLock();
        $site=(int)$input['site_id']; $this->site((int)$input['tenant_id'],$site);
        $current=$this->table('wl_site_domains')->where('site_id',$site)->pluck('domain')->all();
        $wanted=array_column(DomainConfigValidator::normalize($input['domains']),'domain');
        if (array_diff($wanted,$current)) $this->fail('请在域名接入中验证新域名');
        $removed=array_diff($current,$wanted);
        if ($removed && $this->live()->where('site_id',$site)->whereIn('domain',$removed)->exists()) $this->fail('请使用解绑操作移除该域名');
    }

    /** Reuse the application's existing first publication, authorization, snapshot and audit pipeline. */
    public function publishInput(object $b,bool $add): array
    {
        $this->site((int)$b->tenant_id,(int)$b->site_id);
        $rows=$this->table('wl_site_domains')->where('site_id',$b->site_id)->orderBy('domain')->get(['domain','is_primary'])
            ->map(fn($r)=>['domain'=>$r->domain,'is_primary'=>(bool)$r->is_primary])->all();
        // Match DomainConfigStore's byte-wise revision order regardless of database collation.
        usort($rows,static fn($a,$b)=>strcmp($a['domain'],$b['domain']));
        $revision=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
        if ($add) {
            if (!in_array($b->domain,array_column($rows,'domain'),true)) $rows[]=['domain'=>$b->domain,'is_primary'=>!$rows];
        } else {
            foreach ($rows as $r) if ($r['domain']===$b->domain && $r['is_primary']) $this->fail('请先将其他已启用域名设为主域名，再解绑');
            $rows=array_values(array_filter($rows,fn($r)=>$r['domain']!==$b->domain));
        }
        $version=$this->table('wl_site_config_snapshots')->where('site_id',$b->site_id)->max('config_version');
        return ['tenant_id'=>(string)$b->tenant_id,'site_id'=>(string)$b->site_id,'expected_revision'=>$revision,
            'expected_config_version'=>(string)($version??0),'domains'=>$rows];
    }

    public function sweep(callable $invoke, callable $publish, callable $log): int
    {
        $due=$this->rows()->where('actor_type',$this->actor)->where('next_check_at','<=',$this->now())->orderBy('next_check_at')->limit(20)->pluck('binding_id');
        foreach ($due as $id) {
            $lease=(string)Str::uuid();
            $claim=$this->rows()->where('binding_id',$id)->where('actor_type',$this->actor)->where('next_check_at','<=',$this->now())
                ->update(['lease_id'=>$lease,'next_check_at'=>$this->now(180)]);
            if (!$claim) continue;
            $owned=fn()=>$this->rows()->where('binding_id',$id)->where('lease_id',$lease);
            $b=$owned()->first(); if (!$b) continue;
            try {
                $action=match($b->status){'creating'=>'createDomain','deleting'=>'deleteDomain',default=>'getDomainStatus'};
                $r=$b->status==='publishing' ? ['status'=>'active','tenantId'=>$b->cloudfront_tenant_id] : $invoke($action,$b);
                if ($r['status']==='active' && $b->status!=='publishing') {
                    // Persist remote success before local publication. A failed snapshot must not lose the AWS identity.
                    if (!$owned()->update(['status'=>'publishing','cloudfront_tenant_id'=>$r['tenantId']])) continue;
                }
                $this->db->transaction(function() use($owned,$r,$b,$publish) {
                    $this->publicationLock();
                    if (!$owned()->lockForUpdate()->first()) return; // old poll lost to unlink or another lease
                    if ($r['status']==='active') $publish($b,true);
                    $delay=match($r['status']) {
                        'deleting'=>max(1,min(86400,(int)($r['retryAfterSeconds']??30))),
                        'provisioning','pending_validation','deploying'=>60,
                        default=>0,
                    };
                    $diagnostics=array_filter(array_intersect_key($r,array_flip(['domainStatus','certificateStatus','deploymentStatus'])),'is_string');
                    $owned()->update(['cloudfront_tenant_id'=>$r['tenantId'],'status'=>$r['status'],'lease_id'=>null,
                        'upstream_details'=>json_encode(array_map(fn($v)=>substr($v,0,120),$diagnostics),JSON_THROW_ON_ERROR),
                        'attempts'=>0,'last_error'=>null,'next_check_at'=>$delay?$this->now($delay):null,'updated_at'=>$this->now()]);
                });
            } catch (\Throwable $e) {
                $log($id,$e);
                $attempt=(int)$b->attempts+1;
                $owned()->update(['attempts'=>$attempt,'lease_id'=>null,'last_error'=>$attempt>=10?'多次失败，请联系运维核查':'上游或发布暂时失败，后台将重试',
                    'next_check_at'=>$attempt>=10?null:$this->now(min(1800,30*(2**min(6,$attempt)))),'updated_at'=>$this->now()]);
            }
        }
        return count($due);
    }
}
