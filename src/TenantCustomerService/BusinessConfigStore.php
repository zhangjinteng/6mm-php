<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantCustomerService;

use Illuminate\Database\Query\Builder;
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

    private function writer(): ConfigSnapshotWriter
    {
        return new ConfigSnapshotWriter($this->table(...), $this->reject(...));
    }

    public function initializeSiteIfMissing(string $tenantId, string $siteId, int $operatorId, ?string $publishReason = null): array
    {
        $writer = $this->writer();
        $writer->lock();
        $first = $this->table('wl_sites')->where('tenant_id', $tenantId)->orderBy('site_id')->value('site_id');
        if ($siteId !== (string)$first) $this->reject('仅支持当前白标的首个站点');
        if ($writer->latest((int)$tenantId, (int)$siteId)[0]) return ['initialized' => false];
        $this->host->assertActor($operatorId);
        $this->host->assertCanPublish();
        foreach (self::MODULES as $code) {
            if (!$this->businessModules()->where('site_id', $siteId)->where('module_code', $code)->exists()) {
                $this->table('wl_site_modules')->insert(['site_id' => $siteId, 'module_code' => $code,
                    'module_type' => 'business', 'enabled' => false, 'created_at' => \Carbon\CarbonImmutable::now(), 'updated_at' => \Carbon\CarbonImmutable::now()]);
            }
        }
        return ['initialized' => true] + $writer->append((int)$tenantId, (int)$siteId, 0, $operatorId,
            $this->host->actorReference(), $publishReason ?? '首次初始化站点 '.$siteId);
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

    private function context(string $tenantId): array
    {
        $tenant = $this->table('wl_tenants')->where('tenant_id', $tenantId)->first();
        $site = $this->table('wl_sites')->where('tenant_id', $tenantId)->orderBy('site_id')->first();
        if (!$tenant || !$site) $this->reject('白标不存在或尚未配置站点');
        [$version, $target] = $this->writer()->latest((int)$tenantId, (int)$site->site_id);
        if (!$target) $this->reject('默认站点尚未进入发布快照，请先完成站点初始化');
        $enabled = $this->businessModules()->where('site_id', $site->site_id)->where('enabled', true)->distinct()->pluck('module_code')->all();
        // Feature switches have a separate editor. Only enforce source/snapshot
        // agreement for business authorizations here; append publishes the complete set.
        $features = $this->table('wl_site_modules')->where('site_id', $site->site_id)->where('module_type', 'feature')->pluck('module_code')->all();
        $published = array_values(array_diff($target->entitlements, $features));
        sort($enabled);
        sort($published);
        if ($enabled !== $published || (string) $target->entitlementVersion !== (string) $site->entitlement_version) {
            $this->reject('模块表与已发布快照不一致，请先核对配置');
        }
        return [$tenant, $site, $version, $target];
    }

    public function detail(array $input): array
    {
        [$tenant, $site, $version, $target] = $this->context((string) $input['tenant_id']);
        return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
            'site_id' => (string) $site->site_id, 'site_name' => $site->site_name,
            'entitlement_version' => (string) $site->entitlement_version,
            'snapshot_version' => (string)$version, 'config_version' => (string)$version,
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
                $this->writer()->lock();
                [, $site, $version] = $this->context((string) $input['tenant_id']);
                $this->assertVersion($site, $version, $input);
                $this->assertSingleCustomerSite((string)$input['tenant_id']);
                $this->assertCustomerClassification((string)$site->site_id);
            }, fn (string $operationId) => $this->publishLocal($input, $operatorId, $operationId));
        }
        return $this->publishLocal($input, $operatorId);
    }

    /** Validate every affected site before HTTP and again before committing publication. */
    private function assertSingleCustomerSite(string $tenantId): void
    {
        if ($this->table('wl_sites')->where('tenant_id', $tenantId)->count() !== 1) {
            $this->reject('客服开关作用于整个租户，暂不支持多站点发布');
        }
    }

    private function publishLocal(array $input, int $operatorId, ?string $operationId = null): array
    {
        return $this->host->db->transaction(function () use ($input, $operatorId, $operationId): array {
            $this->writer()->lock();
            [, $site, $version, $target] = $this->context((string) $input['tenant_id']);
            $this->assertVersion($site, $version, $input);
            if (!$operationId && array_key_exists(CustomerService::MODULE, $input['modules'])) {
                $op = (new CustomerLifecycleService($this->host))->operation((string) $input['tenant_id']);
                if ($op && $op['status'] !== 'failed') $this->reject('客服操作待核验，不能使用迁移覆盖');
            }
            if (array_key_exists(CustomerService::MODULE, $input['modules'])) $this->assertCustomerClassification((string) $site->site_id);
            if (($input['modules'][CustomerService::MODULE] ?? false) === true && !(new CustomerService($this->host))->bound((string) $input['tenant_id'])) $this->reject('客服账号尚未绑定，不能开启业务');
            if (array_key_exists(CustomerService::MODULE, $input['modules'])) $this->assertSingleCustomerSite((string)$input['tenant_id']);
            foreach ($input['modules'] as $code => $enabled) {
                if (!in_array($code, self::MODULES, true) || !is_bool($enabled)) $this->reject('模块参数无效');
                if (in_array($code, $target->entitlements, true) === $enabled) continue;
                $existing = $this->businessModules()->where('site_id', $site->site_id)->where('module_code', $code);
                if ($existing->exists()) {
                    $existing->update(['enabled' => $enabled, 'updated_at' => \Carbon\CarbonImmutable::now()]);
                } else {
                    $this->table('wl_site_modules')->insert(['site_id' => $site->site_id, 'module_code' => $code,
                        'module_type' => 'business', 'enabled' => $enabled, 'created_at' => \Carbon\CarbonImmutable::now(), 'updated_at' => \Carbon\CarbonImmutable::now()]);
                }
            }
            if ($operationId) (new CustomerLifecycleService($this->host))->complete((string) $input['tenant_id'], $operationId, $operatorId);
            $result = $this->writer()->append((int)$input['tenant_id'], (int)$site->site_id, $version, $operatorId,
                $this->host->actorReference(), $input['reason']);
            return $result + ['entitlement_version'=>(string)$this->table('wl_sites')->where('site_id',$site->site_id)->value('entitlement_version')];
        }, 3);
    }

    private function assertVersion(object $site, int $version, array $input): void
    {
        if ((string)$version !== (string)($input['expected_config_version'] ?? '')) $this->reject('站点配置已变化，请重新打开配置');
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

