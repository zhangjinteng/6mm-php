<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantFeatures;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Carbon\CarbonImmutable;

final class FeatureConfigStore
{
    public const CODE = 'trade_ranking';

    public function __construct(private ConnectionInterface $db, private bool $enabledSitesOnly = false, private bool $allowSiteSelection = false, private ?string $schema = null)
    {
        if ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) throw new \InvalidArgumentException('Invalid feature schema');
    }

    private function table(string $name): Builder
    {
        $schema = $this->schema ?? ($this->db->getDriverName() === 'pgsql' ? 'control_plane' : '');
        return $this->db->table(($schema !== '' ? $schema . '.' : '') . $name);
    }

    private function reject(string $message): never
    {
        throw new FeatureConfigException($message);
    }

    public function listing(array $input): array
    {
        $query = $this->table('wl_tenants');
        $keyword = trim($input['keyword'] ?? '');
        if ($keyword !== '') {
            $escaped = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
            $query->where(function (Builder $q) use ($keyword, $escaped): void {
                $q->whereRaw("tenant_name LIKE ? ESCAPE '!'", [$escaped]);
                if (ctype_digit($keyword) && strlen($keyword) < 19) $q->orWhere('tenant_id', $keyword);
            });
        }
        $count = (clone $query)->count();
        $tenants = $query->orderBy('tenant_id')->forPage($input['page_no'] ?? 1, $input['page_size'] ?? 20)->get();
        $sites = $this->table('wl_sites')->whereIn('tenant_id', $tenants->pluck('tenant_id'))->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->orderBy('site_id')
            ->get(['site_id', 'tenant_id'])->groupBy('tenant_id')->map(fn ($items) => $items->first());
        $rows = $this->table('wl_site_modules')->whereIn('site_id', $sites->pluck('site_id'))
            ->where('module_type', 'feature')->where('module_code', self::CODE)->get()->keyBy('site_id');
        return ['count' => $count, 'lists' => $tenants->map(function ($tenant) use ($sites, $rows): array {
            $site = $sites->get($tenant->tenant_id);
            $row = $rows->get($site?->site_id);
            $enabled = $row ? (bool) $row->enabled : true;
            return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
                'site_id' => $site ? (string) $site->site_id : null,
                'features' => [self::CODE => $enabled], 'enabled_count' => $enabled ? 1 : 0,
                'updated_at' => $row?->updated_at];
        })->all()];
    }

    private function context(string $tenantId, ?string $siteId = null): array
    {
        foreach (array_filter([$tenantId, $siteId], fn ($value) => $value !== null) as $value) {
            if (!preg_match('/^[1-9][0-9]{0,18}$/D', $value) || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) $this->reject('白标或站点参数不正确');
        }
        $tenant = $this->table('wl_tenants')->where('tenant_id', $tenantId)->first();
        $site = $this->table('wl_sites')->where('tenant_id', $tenantId)
            ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))
            ->when($this->allowSiteSelection && $siteId !== null, fn ($q) => $q->where('site_id', $siteId))->orderBy('site_id')->first();
        if (!$tenant || !$site) $this->reject('白标不存在或尚未配置站点');
        $row = $this->table('wl_site_modules')->where('site_id', $site->site_id)->where('module_code', self::CODE)->first();
        if ($row && $row->module_type !== 'feature') $this->reject('交易排行配置分类异常，请联系管理员');
        return [$tenant, $site, $row];
    }

    public function detail(array $input): array
    {
        [$tenant, $site, $row] = $this->context((string) $input['tenant_id'], isset($input['site_id']) ? (string) $input['site_id'] : null);
        $detail = ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
            'site_id' => (string) $site->site_id, 'site_name' => $site->site_name,
            'features' => [self::CODE => $row ? (bool) $row->enabled : true],
            'expected_updated_at' => $row?->updated_at];
        if ($this->allowSiteSelection) $detail['sites'] = $this->table('wl_sites')->where('tenant_id', $tenant->tenant_id)
            ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->orderBy('site_id')->get(['site_id', 'site_name'])
            ->map(fn ($site) => ['site_id' => (string) $site->site_id, 'site_name' => $site->site_name])->all();
        return $detail;
    }

    public function save(array $input, callable $audit): array
    {
        if (!isset($input['tenant_id'], $input['site_id']) || !is_scalar($input['tenant_id']) || !is_scalar($input['site_id'])) $this->reject('白标或站点参数不正确');
        $features = $input['features'] ?? null;
        if (!is_array($features) || array_keys($features) !== [self::CODE] || !is_bool($features[self::CODE])) $this->reject('仅支持交易排行，开关必须为布尔值');
        if (!array_key_exists('expected_updated_at', $input) || ($input['expected_updated_at'] !== null
            && (!is_string($input['expected_updated_at']) || strlen($input['expected_updated_at']) > 64))) $this->reject('配置版本参数不正确');
        return $this->db->transaction(function () use ($input, $audit): array {
            if ($this->db->getDriverName() === 'pgsql') {
                $this->db->statement("SET LOCAL lock_timeout = '5s'");
                $this->db->statement("SET LOCAL statement_timeout = '15s'");
                // Match the business publisher's table-lock order before reading site rows.
                $this->db->statement('LOCK TABLE control_plane.wl_tenants, control_plane.wl_sites, control_plane.wl_site_modules IN SHARE ROW EXCLUSIVE MODE');
            }
            [$tenant, $site, $row] = $this->context((string) $input['tenant_id'], isset($input['site_id']) ? (string) $input['site_id'] : null);
            if ((string) $site->site_id !== (string) $input['site_id']) $this->reject('默认站点已变化，请重新打开配置');
            if (($row?->updated_at) !== $input['expected_updated_at']) $this->reject('配置已被其他操作员修改，请重新打开配置');
            $before = $row ? (bool) $row->enabled : true;
            $enabled = $input['features'][self::CODE];
            if ($before === $enabled) return ['changed' => false, 'detail' => $this->detail($input)];
            $clock = CarbonImmutable::now();
            if ($row?->updated_at && $clock->lessThanOrEqualTo(CarbonImmutable::parse($row->updated_at))) $clock = CarbonImmutable::parse($row->updated_at)->addMicrosecond();
            $now = $clock->format('Y-m-d H:i:s.uP');
            $data = ['enabled' => $enabled, 'updated_at' => $now];
            if ($row) {
                $this->table('wl_site_modules')->where('site_id', $site->site_id)
                    ->where('module_type', 'feature')->where('module_code', self::CODE)->update($data);
            } else {
                $this->table('wl_site_modules')->insert($data + ['site_id' => $site->site_id,
                    'module_code' => self::CODE, 'module_type' => 'feature', 'created_at' => $now]);
            }
            $audit(['tenant_id' => (string) $tenant->tenant_id, 'site_id' => (string) $site->site_id,
                'before' => [self::CODE => $before], 'features' => [self::CODE => $enabled], 'updated_at' => $now], $now);
            return ['changed' => true, 'detail' => $this->detail($input)];
        }, 3);
    }
}
