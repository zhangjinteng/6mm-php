<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;
use Illuminate\Support\Str;

/** All reads and writes use the caller's trusted table resolver and connection. */
final class ConfigSnapshotWriter
{
    public function __construct(private Closure $table, private Closure $reject) {}

    private function rows(string $name) { return ($this->table)($name); }
    private function siteRows(string $name, int $id) { return $this->rows($name)->where('site_id', $id); }
    private function fail(string $message): never { ($this->reject)($message); throw new \LogicException($message); }
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function lock(): void
    {
        $db = $this->rows('wl_tenants')->getConnection();
        if ($db->transactionLevel() < 1) throw new \LogicException('Publication requires a transaction');
        if ($db->getDriverName() === 'sqlite') return; // isolated tests
        if ($db->getDriverName() !== 'pgsql') throw new \LogicException('Unsupported publication driver');
        $db->statement("SET LOCAL lock_timeout = '5s'");
        $db->statement("SET LOCAL statement_timeout = '15s'");
        $tables = [];
        foreach (['wl_tenants','wl_sites','wl_site_domains','wl_site_modules','wl_site_auth_policies','wl_site_email_templates',
            'wl_site_email_template_versions','wl_site_config_publications','wl_site_config_snapshots','wl_site_config_domains'] as $name) {
            $tables[] = $db->getQueryGrammar()->wrapTable($this->rows($name)->from);
        }
        $db->statement('LOCK TABLE '.implode(', ', $tables).' IN SHARE ROW EXCLUSIVE MODE');
    }

    public function latest(int $tenant, int $site): array
    {
        $row = $this->siteRows('wl_site_config_snapshots', $site)->orderByDesc('config_version')->first();
        if (!$row) return [0, null];
        $payload = json_decode($row->payload);
        if ((int) $row->schema_version !== 4 || !hash_equals($row->payload_sha256, hash('sha256', $row->payload))
            || ($payload->tenantId ?? null) !== $tenant || ($payload->siteId ?? null) !== $site
            || !is_array($payload->domains ?? null) || !is_array($payload->entitlements ?? null)
            || !is_int($payload->entitlementVersion ?? null)) $this->fail('本站快照校验失败或租户不匹配');
        return [(int) $row->config_version, $payload];
    }

    private function domain(string $raw): string
    {
        $domain = strtolower(rtrim($raw, '.'));
        $host = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;
        if (strlen($domain) > 253 || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->fail('站点域名无效');
        }
        return $domain;
    }

    private function domains(int $site): array
    {
        $names = [];
        foreach ($this->siteRows('wl_site_domains', $site)->pluck('domain') as $raw) {
            $name = $this->domain($raw);
            if (isset($names[$name])) $this->fail('站点域名重复');
            $names[$name] = true;
        }
        if (!$names) $this->fail('请先配置至少一个站点域名');
        foreach (['wl_site_domains','wl_site_config_domains'] as $table) {
            foreach ($this->rows($table)->where('site_id', '<>', $site)->pluck('domain') as $other) {
                $other = $this->domain($other);
                foreach ($names as $name => $_) {
                    if ($other === $name || (str_starts_with($other, '*.') && str_ends_with($name, substr($other, 1)))
                        || (str_starts_with($name, '*.') && str_ends_with($other, substr($name, 1)))) $this->fail('域名已被其他站点使用或与通配符冲突');
                }
            }
        }
        $domains = array_keys($names);
        sort($domains, SORT_STRING);
        return $domains;
    }

    /** Caller must acquire lock() BEFORE staging any changes in this transaction. */
    public function append(int $tenant, int $site, int $expected, int $operator, string $actor, string $reason): array
    {
        $db = $this->rows('wl_sites')->getConnection();
        if (!$db->transactionLevel() || $operator < 1 || $site < 1 || $tenant < 1 || !trim($reason)
            || mb_strlen($reason) > 512 || !trim($actor) || mb_strlen($actor) > 128) throw new \LogicException('Invalid publication context');
        [$previous, $old] = $this->latest($tenant, $site);
        if ($expected !== $previous) $this->fail('站点配置已变化，请重新加载后保存');
        $tenantRow = $this->rows('wl_tenants')->where('tenant_id', $tenant)->first();
        $siteRow = $this->siteRows('wl_sites', $site)->where('tenant_id', $tenant)->first();
        if (!$siteRow || !$tenantRow || !in_array($tenantRow->status, ['enabled','disabled'], true)
            || !in_array($siteRow->status, ['enabled','disabled'], true)) $this->fail('站点归属或状态无效');
        $status = $tenantRow->status === 'enabled' && $siteRow->status === 'enabled' ? 'enabled' : 'disabled';
        $availability = $old && $old->status !== $status;
        $domains = $this->domains($site);
        $modules = $this->siteRows('wl_site_modules', $site)->where('enabled', true)->distinct()->orderBy('module_code')->pluck('module_code')->all();
        sort($modules, SORT_STRING);
        $entitlement = (int) $siteRow->entitlement_version;
        if ($old) {
            $before = $old->entitlements;
            sort($before, SORT_STRING);
            $next = $old->entitlementVersion + (int) ($availability || $before !== $modules);
            if ($entitlement === $old->entitlementVersion && $entitlement !== $next) {
                $this->siteRows('wl_sites', $site)->update(['entitlement_version' => $next, 'updated_at' => \Carbon\CarbonImmutable::now()]);
                $entitlement = $next;
            }
            if ($entitlement !== $next) $this->fail('站点授权版本与发布内容不一致');
        }
        if ($entitlement < 1) $this->fail('无效授权版本');
        $policies = $this->siteRows('wl_site_auth_policies', $site);
        $policy = $policies->first();
        $created = !$policy;
        if (!$policy) {
            if (isset($old->authPolicy) || $this->siteRows('wl_site_auth_policy_audits', $site)->exists()) $this->fail('已有策略缺少源配置');
            $policies->insert(['site_id'=>$site,'registration_class'=>'white_label','email_registration_enabled'=>true,
                'email_login_enabled'=>true,'password_management_enabled'=>false,'version'=>2,'updated_by'=>$operator]);
            $policy = $policies->first();
        }
        $auth = ['version'=>(int)$policy->version, 'registrationClass'=>$policy->registration_class,
            'emailRegistrationEnabled'=>(bool)$policy->email_registration_enabled,'emailLoginEnabled'=>(bool)$policy->email_login_enabled,
            'passwordManagementEnabled'=>(bool)$policy->password_management_enabled];
        if ($auth['version'] < 1 || !in_array($auth['registrationClass'], ['white_label','agent_only'], true)
            || ($auth['registrationClass'] === 'agent_only' && ($auth['emailRegistrationEnabled'] || $auth['emailLoginEnabled'] || $auth['passwordManagementEnabled']))
            || ($auth['emailRegistrationEnabled'] && !$auth['emailLoginEnabled'])) $this->fail('认证策略无效');
        if (isset($old->authPolicy) && (object)$auth != $old->authPolicy) $this->fail('认证策略与已发布快照不一致');
        if ($availability && !$created) {
            $policies->update(['version'=>++$auth['version'],'updated_by'=>$operator,'updated_at'=>\Carbon\CarbonImmutable::now()]);
        }

        $templates = $this->siteRows('wl_site_email_templates', $site);
        $template = $templates->first();
        $newTemplate = !$template;
        if (!$template) {
            if (isset($old->emailTemplateRef) || $this->siteRows('wl_site_email_template_versions', $site)->exists()
                || $this->siteRows('wl_site_email_template_audits', $site)->exists()) $this->fail('已有邮件模板缺少源配置');
            if (!trim($siteRow->site_name)) $this->fail('首次初始化邮件模板需要本站名称');
            $body = ['version'=>1,'siteName'=>$siteRow->site_name,'defaultLocale'=>'zh-CN','templates'=>[]];
            $raw = $this->json($body);
            $templates->insert(['site_id'=>$site,'version'=>1,'site_name'=>$body['siteName'],'default_locale'=>'zh-CN','templates'=>'[]','updated_by'=>$operator]);
        } else {
            $entry = $this->siteRows('wl_site_email_template_versions', $site)->where('template_version', $template->version)->first();
            if (!$entry || !hash_equals($entry->payload_sha256, hash('sha256', $entry->payload))) $this->fail('邮件模板版本缺失或校验失败');
            $raw = $entry->payload;
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($body['version'] ?? null) !== (int)$template->version || ($body['siteName'] ?? null) !== $template->site_name
                || ($body['defaultLocale'] ?? null) !== $template->default_locale || !isset($body['templates'])
                || $body['templates'] != json_decode($template->templates, true, 512, JSON_THROW_ON_ERROR)) $this->fail('邮件模板与已发布版本不一致');
        }
        if (strlen($raw) > 131072) $this->fail('邮件模板超过容量限制');
        $ref = ['version'=>$body['version'],'siteName'=>$body['siteName'],'defaultLocale'=>$body['defaultLocale'],'sha256'=>hash('sha256',$raw)];
        if (isset($old->emailTemplateRef) && $old->emailTemplateRef != (object)$ref) $this->fail('快照邮件模板引用与版本不一致');
        $payload = $this->json(['tenantId'=>$tenant,'siteId'=>$site,'domains'=>$domains,'status'=>$status,'entitlementVersion'=>$entitlement,
            'entitlements'=>$modules,'authPolicy'=>$auth,'emailTemplateRef'=>$ref]);
        if (strlen($payload) > 1048576) $this->fail('站点配置超过容量限制');
        $pub = $this->rows('wl_site_config_publications');
        $global = (int)$pub->max('publication_version');
        $map = $this->siteRows('wl_site_config_domains',$site);
        if ($old && json_decode($payload) == $old) {
            $repair = $map->orderBy('domain')->pluck('domain')->all() !== $domains;
            if ($repair) $this->replaceDomains($site,$domains);
            return ['changed'=>$repair,'publication_version'=>(string)$global,'config_version'=>(string)$previous,'snapshot_version'=>(string)$previous];
        }
        $version = $db->getDriverName() === 'sqlite' ? $global+1 :
            (int)$db->selectOne("SELECT nextval('control_plane.wl_snapshot_version_seq'::regclass) AS version")->version;
        if ($version <= max($global,$previous)) $this->fail('发布版本序列异常');
        $pub->insert(['publication_version'=>$version,'published_by'=>$actor,'publish_reason'=>$reason,'published_at'=>\Carbon\CarbonImmutable::now()]);
        $audit = ['site_id'=>$site,'snapshot_version'=>$version,'operator_id'=>$operator,'reason'=>$reason,
            'source_service'=>explode(':',$actor)[0],'request_id'=>(string)Str::uuid()];
        if ($created || $availability) $this->rows('wl_site_auth_policy_audits')->insert($audit+['policy_version'=>$auth['version'],
            'action'=>$created?'create':'availability_change','before_policy'=>$created?null:$this->json($old->authPolicy ?? (object)array_merge($auth,['version'=>$auth['version']-1])),
            'after_policy'=>$this->json($auth)]);
        if ($newTemplate) {
            $this->rows('wl_site_email_template_versions')->insert(['site_id'=>$site,'template_version'=>1,'payload'=>$raw,
                'payload_sha256'=>$ref['sha256'],'published_snapshot_version'=>$version]);
            $this->rows('wl_site_email_template_audits')->insert($audit+['template_version'=>1,'action'=>'create','before_templates'=>null,'after_templates'=>$raw]);
        }
        $this->rows('wl_site_config_snapshots')->insert(['site_id'=>$site,'config_version'=>$version,'schema_version'=>4,'payload'=>$payload,
            'payload_sha256'=>hash('sha256',$payload),'previous_config_version'=>$previous ?: null,'rollback_of_config_version'=>null]);
        $this->replaceDomains($site,$domains);
        return ['changed'=>true,'publication_version'=>(string)$version,'config_version'=>(string)$version,'snapshot_version'=>(string)$version];
    }

    private function replaceDomains(int $site,array $domains): void
    {
        $this->siteRows('wl_site_config_domains',$site)->delete();
        $this->rows('wl_site_config_domains')->insert(array_map(static fn ($domain)=>['domain'=>$domain,'site_id'=>$site],$domains));
    }
}
