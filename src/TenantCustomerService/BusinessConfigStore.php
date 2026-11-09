<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantCustomerService;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\TenantConfig\ConfigSnapshotWriter;

final class BusinessConfigStore
{
    public function __construct(private CustomerServiceContext $host) {}

    private const MODULES = ['contract', 'seconds', CustomerService::MODULE];

    private function table(string $name): Builder
    {
        return $this->host->table($name);
    }

    private function businessModules(): Builder
    {
        return $this->table('wl_site_modules')->where('module_type', 'business');
    }

    private function reject(string $message): never
    {
        $this->host->reject('config', $message);
    }

    private function lockPublication(bool $initializing = false): void
    {
        if ($this->host->db->getDriverName() !== 'pgsql') return;
        $this->host->db->statement("SET LOCAL lock_timeout = '5s'");
        $this->host->db->statement("SET LOCAL statement_timeout = '15s'");
        $this->host->db->statement('LOCK TABLE control_plane.wl_config_snapshots, control_plane.wl_tenants, control_plane.wl_sites, control_plane.wl_site_modules IN SHARE ROW EXCLUSIVE MODE');
        if ($initializing) {
            $this->host->db->statement('LOCK TABLE control_plane.wl_site_domains, control_plane.wl_site_auth_policies, control_plane.wl_site_email_templates, control_plane.wl_site_email_template_versions IN SHARE MODE');
        }
    }

    private function appendSnapshot(object $snapshot, object $payload, string $reason): string
    {
        return ConfigSnapshotWriter::append(
            $this->table('wl_config_snapshots'), $snapshot, $payload, $this->host->actorReference(),
            $reason, \Carbon\CarbonImmutable::now(), $this->reject(...),
        );
    }

    /** Called before account row locks, inside the same account-edit transaction. */
    public function initializeSiteIfMissing(string $tenantId, string $siteId, int $operatorId, ?string $publishReason = null): array
    {
        if ($this->host->db->transactionLevel() < 1) throw new \LogicException('Site initialization requires a transaction');
        $this->lockPublication(true);
        $site = $this->table('wl_sites')->where('tenant_id', $tenantId)->where('site_id', $siteId)->first();
        if (!$site) $this->reject('所选站点不属于该白标');
        [$snapshot, $payload] = $this->snapshot();
        foreach ($payload->sites as $published) {
            if ((string) $published->siteId !== $siteId) continue;
            if ((string) $published->tenantId !== $tenantId) $this->reject('快照中的站点归属不一致');
            return ['initialized' => false];
        }
        $this->host->assertActor($operatorId);
        $this->host->assertCanPublish();
        $domains = $this->table('wl_site_domains')->where('site_id', $siteId)->orderBy('domain')->pluck('domain')->all();
        if (!$domains) $this->reject('首次发布失败：请先配置站点域名');
        $normalized = [];
        foreach ($domains as $domain) {
            $host = strtolower(rtrim($domain, '.'));
            if ($domain !== trim($domain) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || isset($normalized[$host])) $this->reject('首次发布失败：站点域名无效或重复');
            $normalized[$host] = true;
        }
        foreach ($this->table('wl_site_domains')->where('site_id', '<>', $siteId)->pluck('domain') as $domain) {
            if (isset($normalized[strtolower(rtrim($domain, '.'))])) $this->reject('首次发布失败：域名已属于其他站点');
        }
        foreach ($payload->sites as $published) foreach ($published->domains as $domain) {
            if (!is_string($domain) || isset($normalized[strtolower(rtrim($domain, '.'))])) {
                $this->reject('首次发布失败：快照域名无效或与其他站点冲突');
            }
        }
        if (!in_array($site->status, ['enabled', 'disabled'], true) || (int) $site->entitlement_version < 1) {
            $this->reject('首次发布失败：站点状态或授权版本无效');
        }
        $target = (object) ['tenantId' => (int) $tenantId, 'siteId' => (int) $siteId,
            'domains' => $domains, 'status' => $site->status, 'entitlementVersion' => (int) $site->entitlement_version,
            'entitlements' => $this->businessModules()->where('site_id', $siteId)->where('enabled', true)->orderBy('module_code')->pluck('module_code')->all()];
        $policy = $this->table('wl_site_auth_policies')->where('site_id', $siteId)->first();
        if ($policy) {
            if ((int) $policy->version < 1 || !in_array($policy->registration_class, ['white_label', 'agent_only'], true)
                || ($policy->registration_class === 'agent_only' && ($policy->email_registration_enabled || $policy->email_login_enabled))
                || ($policy->email_registration_enabled && !$policy->email_login_enabled)
                || ($policy->password_management_enabled && $policy->registration_class !== 'white_label')) {
                $this->reject('首次发布失败：认证策略无效');
            }
            $target->authPolicy = (object) ['version' => (int) $policy->version, 'registrationClass' => $policy->registration_class,
                'emailRegistrationEnabled' => (bool) $policy->email_registration_enabled, 'emailLoginEnabled' => (bool) $policy->email_login_enabled,
                'passwordManagementEnabled' => (bool) $policy->password_management_enabled];
        }
        $template = $this->table('wl_site_email_templates')->where('site_id', $siteId)->first();
        if ($template) {
            $version = $this->table('wl_site_email_template_versions')->where('site_id', $siteId)->where('template_version', $template->version)->first();
            if (!$version || !hash_equals($version->payload_sha256, hash('sha256', $version->payload))) {
                $this->reject('首次发布失败：邮件模板版本缺失或校验失败');
            }
            $templatePayload = json_decode($version->payload, true, 512, JSON_THROW_ON_ERROR);
            if (($templatePayload['version'] ?? null) !== (int) $template->version
                || ($templatePayload['siteName'] ?? null) !== $template->site_name
                || ($templatePayload['defaultLocale'] ?? null) !== $template->default_locale
                || ($templatePayload['templates'] ?? null) != json_decode($template->templates, true, 512, JSON_THROW_ON_ERROR)) {
                $this->reject('首次发布失败：邮件模板与版本记录不一致');
            }
            $target->emailTemplateRef = (object) ['version' => (int) $template->version, 'siteName' => $template->site_name,
                'defaultLocale' => $template->default_locale, 'sha256' => $version->payload_sha256];
        }
        foreach (self::MODULES as $code) {
            if (!$this->businessModules()->where('site_id', $siteId)->where('module_code', $code)->exists()) {
                $this->table('wl_site_modules')->insert(['site_id' => $siteId, 'module_code' => $code,
                    'module_type' => 'business', 'enabled' => false, 'created_at' => \Carbon\CarbonImmutable::now(), 'updated_at' => \Carbon\CarbonImmutable::now()]);
            }
        }
        $payload->sites[] = $target;
        return ['initialized' => true, 'snapshot_version' => $this->appendSnapshot($snapshot, $payload,
            $publishReason ?? '编辑白标保存时首次初始化站点 '.$siteId)];
    }

    public function listing(array $input): array
    {
        $query = $this->table('wl_tenants');
        $keyword = trim($input['keyword'] ?? '');
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($keyword): void {
                $q->whereRaw("tenant_name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%']);
                if (ctype_digit($keyword) && strlen($keyword) < 19) $q->orWhere('tenant_id', $keyword);
            });
        }
        $count = (clone $query)->count();
        $tenants = $query->orderBy('tenant_id')->forPage($input['page_no'] ?? 1, $input['page_size'] ?? 20)->get();
        $sites = $this->table('wl_sites')->whereIn('tenant_id', $tenants->pluck('tenant_id'))->orderBy('site_id')->get()->groupBy('tenant_id');
        $firstSites = $sites->map(fn ($items) => $items->first());
        $modules = $this->businessModules()->whereIn('site_id', $firstSites->pluck('site_id'))->get()->groupBy('site_id');
        $customers = $this->table('wl_channel_configs')->whereIn('tenant_id', $tenants->pluck('tenant_id'))->pluck('customer_service', 'tenant_id');
        return ['count' => $count, 'lists' => $tenants->map(function ($tenant) use ($firstSites, $modules, $customers): array {
            $site = $firstSites->get($tenant->tenant_id);
            $flags = array_fill_keys(self::MODULES, false);
            $updated = $site?->updated_at;
            foreach ($modules->get($site?->site_id, collect()) as $module) {
                if (array_key_exists($module->module_code, $flags)) {
                    $flags[$module->module_code] = (bool) $module->enabled;
                    if ($module->updated_at && (!$updated || strtotime($module->updated_at) > strtotime($updated))) $updated = $module->updated_at;
                }
            }
            return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
                'site_id' => $site ? (string) $site->site_id : null, 'modules' => $flags,
                'customer_operation' => CustomerLifecycleService::publicOperation(json_decode($customers->get($tenant->tenant_id, '{}'), true, 512, JSON_THROW_ON_ERROR)),
                'enabled_count' => count(array_filter($flags)), 'updated_at' => $updated];
        })->all()];
    }

    private function snapshot(): array
    {
        $row = $this->table('wl_config_snapshots')->orderByDesc('snapshot_version')->first();
        if (!$row || !hash_equals($row->payload_sha256, hash('sha256', $row->payload))) {
            $this->reject('最新快照缺失或校验失败，请联系管理员');
        }
        try {
            // Decode as objects to preserve empty objects and all unmodified extension fields.
            $payload = json_decode($row->payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->reject('最新快照 JSON 无效，请联系管理员');
        }
        if (!is_object($payload) || ($payload->schemaVersion ?? null) !== 4 || (int) $row->schema_version !== 4
            || !is_int($payload->snapshotVersion ?? null) || $payload->snapshotVersion !== (int) $row->snapshot_version
            || !is_array($payload->sites ?? null)) {
            $this->reject('最新快照版本或 schema 不受支持，请联系管理员');
        }
        $seen = [];
        foreach ($payload->sites as $site) {
            if (!is_object($site) || !is_int($site->siteId ?? null) || $site->siteId < 1
                || !is_int($site->tenantId ?? null) || $site->tenantId < 1
                || !is_array($site->domains ?? null) || !is_string($site->status ?? null)
                || !is_int($site->entitlementVersion ?? null) || $site->entitlementVersion < 1
                || isset($seen[$site->siteId]) || !is_array($site->entitlements ?? null)
                || count(array_filter($site->entitlements, 'is_string')) !== count($site->entitlements)) {
                $this->reject('最新快照站点结构无效，请联系管理员');
            }
            if (count(array_unique($site->entitlements)) !== count($site->entitlements)) {
                $this->reject('最新快照包含重复模块，请联系管理员');
            }
            $seen[$site->siteId] = true;
        }
        return [$row, $payload];
    }

    private function context(string $tenantId): array
    {
        $tenant = $this->table('wl_tenants')->where('tenant_id', $tenantId)->first();
        $site = $this->table('wl_sites')->where('tenant_id', $tenantId)->orderBy('site_id')->first();
        if (!$tenant || !$site) $this->reject('白标不存在或尚未配置站点');
        [$snapshot, $payload] = $this->snapshot();
        $matches = array_values(array_filter($payload->sites, fn ($s) => (string) $s->siteId === (string) $site->site_id));
        if (count($matches) !== 1 || (string) $matches[0]->tenantId !== (string) $tenant->tenant_id) {
            $this->reject('默认站点尚未进入发布快照，请先完成站点初始化');
        }
        $target = $matches[0];
        $enabled = $this->businessModules()->where('site_id', $site->site_id)->where('enabled', true)->pluck('module_code')->all();
        $published = $target->entitlements;
        sort($enabled);
        sort($published);
        if ($enabled !== $published || (string) $target->entitlementVersion !== (string) $site->entitlement_version) {
            $this->reject('模块表与已发布快照不一致，请先核对配置');
        }
        return [$tenant, $site, $snapshot, $payload, $target];
    }

    public function detail(array $input): array
    {
        [$tenant, $site, $snapshot, , $target] = $this->context((string) $input['tenant_id']);
        return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
            'site_id' => (string) $site->site_id, 'site_name' => $site->site_name,
            'entitlement_version' => (string) $site->entitlement_version,
            'snapshot_version' => (string) $snapshot->snapshot_version,
            'customer_operation' => (new CustomerLifecycleService($this->host))->operation((string) $tenant->tenant_id),
            'modules' => array_combine(self::MODULES, array_map(fn ($code) => in_array($code, $target->entitlements, true), self::MODULES))];
    }

    public function publish(array $input, int $operatorId): array
    {
        $this->host->assertActor($operatorId);
        $this->host->assertCanPublish();
        if (array_key_exists(CustomerService::MODULE, $input['modules']) && !($input['require_existing_customer_binding'] ?? false)) {
            if (!is_bool($input['modules'][CustomerService::MODULE])) $this->reject('客服模块参数无效');
            return (new CustomerLifecycleService($this->host))->transition($input, $operatorId, function () use ($input): void {
                $this->lockPublication();
                [, $site, , $payload] = $this->context((string) $input['tenant_id']);
                $this->assertVersion($site, $input);
                $this->customerTargets((string) $input['tenant_id'], $payload);
            }, fn (string $operationId) => $this->publishLocal($input, $operatorId, $operationId));
        }
        return $this->publishLocal($input, $operatorId);
    }

    /** Validate every affected site before HTTP and again before committing publication. */
    private function customerTargets(string $tenantId, object $payload): array
    {
        $targets = [];
        foreach ($this->table('wl_sites')->where('tenant_id', $tenantId)->orderBy('site_id')->get() as $site) {
            $this->assertCustomerClassification((string) $site->site_id);
            $matches = array_values(array_filter($payload->sites, fn ($s) => (string) $s->siteId === (string) $site->site_id
                && (string) $s->tenantId === $tenantId));
            if (count($matches) !== 1) $this->reject('白标有站点尚未进入快照，请先初始化后切换客服');
            $target = $matches[0];
            $enabled = $this->businessModules()->where('site_id', $site->site_id)->where('enabled', true)->pluck('module_code')->all();
            $published = $target->entitlements;
            sort($enabled); sort($published);
            if ($enabled !== $published || (string) $target->entitlementVersion !== (string) $site->entitlement_version) {
                $this->reject('白标站点模块与快照不一致，请先核验配置');
            }
            $targets[] = $target;
        }
        return $targets;
    }

    private function publishLocal(array $input, int $operatorId, ?string $operationId = null): array
    {
        return $this->host->db->transaction(function () use ($input, $operatorId, $operationId): array {
            $this->lockPublication();
            [, $site, $snapshot, $payload, $target] = $this->context((string) $input['tenant_id']);
            $this->assertVersion($site, $input);
            if (!$operationId && array_key_exists(CustomerService::MODULE, $input['modules'])) {
                $op = (new CustomerLifecycleService($this->host))->operation((string) $input['tenant_id']);
                if ($op && $op['status'] !== 'failed') $this->reject('客服操作待核验，不能使用迁移覆盖');
            }
            if (array_key_exists(CustomerService::MODULE, $input['modules'])) $this->assertCustomerClassification((string) $site->site_id);
            if (($input['modules'][CustomerService::MODULE] ?? false) === true && !(new CustomerService($this->host))->bound((string) $input['tenant_id'])) $this->reject('客服账号尚未绑定，不能开启业务');
            $targets = $operationId ? $this->customerTargets((string) $input['tenant_id'], $payload) : [$target];
            $changed = false;
            foreach ($targets as $affected) {
                $siteChanged = false;
                foreach ($input['modules'] as $code => $enabled) {
                    if (!in_array($code, self::MODULES, true) || !is_bool($enabled)) $this->reject('模块参数无效');
                    if ($affected !== $target && $code !== CustomerService::MODULE) continue;
                    if (in_array($code, $affected->entitlements, true) === $enabled) continue;
                    $changed = $siteChanged = true;
                    $existing = $this->businessModules()->where('site_id', $affected->siteId)->where('module_code', $code);
                    if ($existing->exists()) {
                        $existing->update(['enabled' => $enabled, 'updated_at' => \Carbon\CarbonImmutable::now()]);
                    } else {
                        $this->table('wl_site_modules')->insert(['site_id' => $affected->siteId, 'module_code' => $code,
                            'module_type' => 'business', 'enabled' => $enabled, 'created_at' => \Carbon\CarbonImmutable::now(), 'updated_at' => \Carbon\CarbonImmutable::now()]);
                    }
                    $affected->entitlements = array_values(array_filter($affected->entitlements, fn ($value) => $value !== $code));
                    if ($enabled) $affected->entitlements[] = $code;
                }
                if ($siteChanged) {
                    $affected->entitlementVersion++;
                    $this->table('wl_sites')->where('site_id', $affected->siteId)->update([
                        'entitlement_version' => $affected->entitlementVersion, 'updated_at' => \Carbon\CarbonImmutable::now()]);
                }
            }
            if ($operationId) (new CustomerLifecycleService($this->host))->complete((string) $input['tenant_id'], $operationId, $operatorId);
            if (!$changed) return ['changed' => false, 'snapshot_version' => (string) $snapshot->snapshot_version];
            $version = $this->appendSnapshot($snapshot, $payload, $input['reason']);
            return ['changed' => true, 'snapshot_version' => (string) $version,
                'entitlement_version' => (string) $target->entitlementVersion];
        }, 3);
    }

    private function assertVersion(object $site, array $input): void
    {
        if ((string) $site->site_id !== (string) $input['site_id']) $this->reject('默认站点已变化，请重新打开配置');
        if ((string) $site->entitlement_version !== (string) $input['expected_entitlement_version']) $this->reject('配置已被其他操作员修改，请重新打开配置');
    }

    private function assertCustomerClassification(string $siteId): void
    {
        if ($this->table('wl_site_modules')->where('site_id', $siteId)->where('module_code', CustomerService::MODULE)
            ->where('module_type', '<>', 'business')->exists()) $this->reject('客服模块分类异常，不能覆盖非业务模块');
    }

    public function toggleCustomer(array $input, int $operatorId): array
    {
        $input['modules'] = [CustomerService::MODULE => $input['enabled']];
        $input['reason'] = $input['enabled'] ? '开启6MM客服（未开户时自动开通）' : '关闭6MM客服';
        $result = $this->publish($input, $operatorId);
        return $result + ['detail' => $this->detail(['tenant_id' => $input['tenant_id']])];
    }
}

