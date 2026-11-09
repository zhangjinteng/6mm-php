<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantFeatures;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Carbon\CarbonImmutable;
use SixMm\Shared\TenantConfig\ConfigSnapshotWriter;

final class FeatureConfigStore
{
    public const CODE = 'trade_ranking';
    public const CONTENT_CODES = ['news', 'academy', 'help_center', 'feedback', 'announcement'];

    public function __construct(private ConnectionInterface $db, private bool $enabledSitesOnly = false, private bool $allowSiteSelection = false, private ?string $schema = null, private bool $contentFeatures = false)
    {
        if ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) throw new \InvalidArgumentException('Invalid feature schema');
    }

    private function codes(): array
    {
        return $this->contentFeatures ? [self::CODE, ...self::CONTENT_CODES] : [self::CODE];
    }

    private function values(array $rows): array
    {
        $values = [];
        foreach ($this->codes() as $code) $values[$code] = (bool) ($rows[$code]->enabled ?? false);
        return $values;
    }

    private function latestUpdatedAt(array $rows): ?string
    {
        $latest = null;
        foreach ($rows as $row) {
            if ($row->updated_at !== null && ($latest === null || CarbonImmutable::parse($row->updated_at)->greaterThan(CarbonImmutable::parse($latest)))) $latest = $row->updated_at;
        }
        return $latest;
    }

    private function revision(string $siteId, array $rows): string
    {
        $state = [];
        foreach ($this->codes() as $code) {
            $row = $rows[$code] ?? null;
            $state[$code] = $row ? [(bool) $row->enabled, $row->created_at, $row->updated_at] : null;
        }
        return hash('sha256', json_encode([$siteId, $state], JSON_THROW_ON_ERROR));
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
            $enabled = (bool) ($row->enabled ?? false);
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
        $rows = $this->table('wl_site_modules')->where('site_id', $site->site_id)->whereIn('module_code', $this->codes())->get()->keyBy('module_code')->all();
        foreach ($rows as $row) if ($row->module_type !== 'feature') $this->reject('功能开关配置分类异常，请联系管理员');
        return [$tenant, $site, $rows];
    }

    public function detail(array $input): array
    {
        [$tenant, $site, $rows] = $this->context((string) $input['tenant_id'], isset($input['site_id']) ? (string) $input['site_id'] : null);
        $detail = ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
            'site_id' => (string) $site->site_id, 'site_name' => $site->site_name,
            'features' => $this->values($rows),
            'expected_updated_at' => $this->latestUpdatedAt($rows)];
        if ($this->contentFeatures) $detail['expected_revision'] = $this->revision((string) $site->site_id, $rows);
        if ($this->allowSiteSelection) $detail['sites'] = $this->table('wl_sites')->where('tenant_id', $tenant->tenant_id)
            ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->orderBy('site_id')->get(['site_id', 'site_name'])
            ->map(fn ($site) => ['site_id' => (string) $site->site_id, 'site_name' => $site->site_name])->all();
        return $detail;
    }

    public function save(array $input, int $operatorId, string $actor, callable $audit): array
    {
        if (!isset($input['tenant_id'], $input['site_id']) || !is_scalar($input['tenant_id']) || !is_scalar($input['site_id'])) $this->reject('白标或站点参数不正确');
        $features = $input['features'] ?? null;
        if (!is_array($features) || !$features || array_diff(array_keys($features), $this->codes())) $this->reject('不支持的功能开关字段');
        foreach ($features as $value) if (!is_bool($value)) $this->reject('功能开关必须为布尔值');
        $hasRevision = array_key_exists('expected_revision', $input);
        if ($hasRevision && (!$this->contentFeatures || !is_string($input['expected_revision']) || !preg_match('/^[a-f0-9]{64}$/D', $input['expected_revision']))) $this->reject('配置版本参数不正确');
        if (array_intersect(array_keys($features), self::CONTENT_CODES) && !$hasRevision) $this->reject('内容开关需要配置版本，请刷新后重试');
        if (!array_key_exists('expected_updated_at', $input) || ($input['expected_updated_at'] !== null
            && (!is_string($input['expected_updated_at']) || strlen($input['expected_updated_at']) > 64))) $this->reject('配置版本参数不正确');
        return $this->db->transaction(function () use ($input, $operatorId, $actor, $audit): array {
            $writer = new ConfigSnapshotWriter($this->table(...), $this->reject(...));
            $writer->lock();
            [$tenant, $site, $rows] = $this->context((string) $input['tenant_id'], isset($input['site_id']) ? (string) $input['site_id'] : null);
            if ((string) $site->site_id !== (string) $input['site_id']) $this->reject('默认站点已变化，请重新打开配置');
            if (array_key_exists('expected_revision', $input)) {
                if (!hash_equals($this->revision((string) $site->site_id, $rows), $input['expected_revision'])) $this->reject('配置已被其他操作员修改，请重新打开配置');
            } elseif ($this->latestUpdatedAt($rows) !== $input['expected_updated_at']) $this->reject('配置已被其他操作员修改，请重新打开配置');
            $before = $this->values($rows);
            $changes = [];
            foreach ($input['features'] as $code => $enabled) if ($before[$code] !== $enabled) $changes[$code] = $enabled;
            if (!$changes) return ['changed' => false, 'detail' => $this->detail($input)];
            [$expected] = $writer->latest((int) $tenant->tenant_id, (int) $site->site_id);
            $clock = CarbonImmutable::now();
            $latest = $this->latestUpdatedAt($rows);
            if ($latest && $clock->lessThanOrEqualTo(CarbonImmutable::parse($latest))) $clock = CarbonImmutable::parse($latest)->addMicrosecond();
            $now = $clock->format('Y-m-d H:i:s.uP');
            foreach ($changes as $code => $enabled) {
                $data = ['enabled' => $enabled, 'updated_at' => $now];
                if (isset($rows[$code])) {
                    $this->table('wl_site_modules')->where('site_id', $site->site_id)
                        ->where('module_type', 'feature')->where('module_code', $code)->update($data);
                } else {
                    $this->table('wl_site_modules')->insert($data + ['site_id' => $site->site_id,
                        'module_code' => $code, 'module_type' => 'feature', 'created_at' => $now]);
                }
            }
            $writer->append((int) $tenant->tenant_id, (int) $site->site_id, $expected, $operatorId, $actor, '保存功能开关');
            $audit(['tenant_id' => (string) $tenant->tenant_id, 'site_id' => (string) $site->site_id,
                'before' => array_intersect_key($before, $changes), 'features' => $changes, 'updated_at' => $now], $now);
            return ['changed' => true, 'detail' => $this->detail($input)];
        }, 3);
    }
}
