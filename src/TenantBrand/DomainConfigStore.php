<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Carbon\CarbonImmutable;
use SixMm\Shared\TenantConfig\ConfigSnapshotWriter;
use SixMm\Shared\Media\MediaRegistry;

final class DomainConfigStore
{
    public function __construct(private ConnectionInterface $db, private bool $enabledSitesOnly = false, private ?string $schema = null)
    {
        if ($schema !== null && !preg_match('/^[a-z_][a-z0-9_]*$/D', $schema)) throw new \InvalidArgumentException('Invalid domain schema');
    }

    private function table(string $name, string $schema = 'control_plane'): Builder
    {
        $prefix = $this->schema ?? ($this->db->getDriverName() === 'pgsql' ? $schema : '');
        return $this->db->table(($prefix !== '' ? $prefix . '.' : '') . $name);
    }

    private function reject(string $message): never
    {
        throw new DomainConfigException($message);
    }

    public function listing(array $input): array
    {
        $query = $this->table('wl_tenants');
        $keyword = trim($input['keyword'] ?? '');
        if ($keyword !== '') {
            $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->where(function (Builder $q) use ($keyword, $pattern): void {
                $q->whereRaw("LOWER(tenant_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                if (ctype_digit($keyword) && strlen($keyword) < 19) $q->orWhere('tenant_id', $keyword);
            });
        }
        $count = (clone $query)->count();
        $tenants = $query->orderBy('tenant_id')->forPage($input['page_no'] ?? 1, $input['page_size'] ?? 20)->get();
        $sites = $this->table('wl_sites')->whereIn('tenant_id', $tenants->pluck('tenant_id'))
            ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->orderBy('site_id')->get();
        $domains = $this->table('wl_site_domains')->whereIn('site_id', $sites->pluck('site_id'))->orderBy('domain')->get();
        return ['count' => $count, 'lists' => $tenants->map(function ($tenant) use ($sites, $domains): array {
            $owned = $sites->where('tenant_id', $tenant->tenant_id);
            $rows = $domains->whereIn('site_id', $owned->pluck('site_id'));
            $primary = $rows->filter(fn ($d) => (bool) $d->is_primary)->map(fn ($d) => [
                'site_id' => (string) $d->site_id, 'domain' => $d->domain,
            ])->values()->all();
            $dates = array_filter(array_merge([$tenant->updated_at], $owned->pluck('updated_at')->all()));
            sort($dates);
            return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
                'site_count' => $owned->count(), 'domain_count' => $rows->count(), 'primary_domains' => $primary,
                'missing_primary_count' => $owned->filter(fn ($site) => $rows->where('site_id', $site->site_id)->isNotEmpty()
                    && !$rows->where('site_id', $site->site_id)->contains(fn ($d) => (bool) $d->is_primary))->count(),
                'updated_at' => $dates === [] ? null : end($dates)];
        })->all()];
    }

    private function domains(string $siteId): array
    {
        $rows = $this->table('wl_site_domains')->where('site_id', $siteId)->get(['domain', 'is_primary'])
            ->map(fn ($d): array => ['domain' => $d->domain, 'is_primary' => (bool) $d->is_primary])->all();
        usort($rows, fn ($a, $b) => strcmp($a['domain'], $b['domain']));
        return $rows;
    }

    private function writer(): ConfigSnapshotWriter
    {
        return new ConfigSnapshotWriter($this->table(...), $this->reject(...));
    }

    private function revision(array $domains): string
    {
        return hash('sha256', json_encode($domains, JSON_THROW_ON_ERROR));
    }

    private function assets(): SiteBrandAssets { return new SiteBrandAssets($this->db, $this->schema); }

    public function upload(array $input, int $operatorId): array
    {
        $detail = $this->detail($input);
        if ($operatorId <= 0 || !in_array((string)($input['site_id'] ?? ''), array_column($detail['sites'], 'site_id'), true)) {
            $this->reject('站点不属于当前白标或已不存在');
        }
        return (new MediaRegistry())->upload($input['file'], $operatorId, (int)$input['tenant_id']);
    }

    public function detail(array $input): array
    {
        return $this->db->transaction(function () use ($input): array {
            if ($this->db->getDriverName() === 'pgsql') $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $tenant = $this->table('wl_tenants')->where('tenant_id', $input['tenant_id'])->first();
            if (!$tenant) $this->reject('白标不存在');
            $sites = $this->table('wl_sites')->where('tenant_id', $tenant->tenant_id)
                ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->orderBy('site_id')->limit(1)->get();
            return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
                'sites' => $sites->map(function ($site): array {
                    $domains = $this->domains((string) $site->site_id);
                    $brand = $this->assets()->read((string)$site->site_id);
                    return ['site_id' => (string) $site->site_id, 'site_name' => $site->site_name,
                        'config_version' => (string)$this->writer()->latest((int)$site->tenant_id, (int)$site->site_id)[0],
                        'domains' => $domains, 'revision' => $this->revision($domains), 'updated_at' => $site->updated_at ?? null,
                        'brand_revision' => SiteBrandAssets::revision($brand)] + $brand;
                })->all()];
        });
    }

    public function save(array $input, int $operatorId, callable $audit, string $actorType = 'platform'): array
    {
        if ($operatorId <= 0 || !in_array($actorType, ['platform', 'white_label'], true)) throw new \InvalidArgumentException('Invalid brand actor');
        if (!is_string($input['expected_revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['expected_revision'])) $this->reject('域名版本参数不正确');
        $desired = DomainConfigValidator::normalize($input['domains'] ?? null);
        return $this->db->transaction(function () use ($input, $operatorId, $desired, $audit, $actorType): array {
            $writer = $this->writer();
            $writer->lock();
            $site = $this->table('wl_sites')->where('tenant_id', $input['tenant_id'])
                ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->orderBy('site_id')->lockForUpdate()->first();
            if (!$site || (string)$site->site_id !== (string)$input['site_id']) $this->reject('站点不属于当前白标或已不存在');
            $timestamp = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            $current = $this->domains((string) $site->site_id);
            $beforeBrand = $this->assets()->read((string)$site->site_id);
            $desiredBrand = SiteBrandAssets::desired($input, $beforeBrand);
            if (!hash_equals($this->revision($current), $input['expected_revision'])) $this->reject('域名已被其他操作员修改，请重新加载后再保存');
            foreach ($desired as $row) {
                if ($this->table('wl_site_domains')->where('domain', $row['domain'])->where('site_id', '<>', $site->site_id)->exists()) {
                    $this->reject('域名已被其他站点使用');
                }
            }
            $names = array_column($desired, 'domain');
            [$version, $old] = $writer->latest((int)$site->tenant_id, (int)$site->site_id);
            if ((string)$version !== (string)($input['expected_config_version'] ?? '')) $this->reject('站点配置已变化，请重新加载后保存');
            if (!$old) $this->reject('站点尚未发布，请先由平台完成初始化');
            $oldDomains = $old->domains;
            sort($oldDomains, SORT_STRING);
            if ($oldDomains !== array_column($current, 'domain')) $this->reject('域名表与发布快照不一致');
            if ($current !== $desired) {
                $this->table('wl_site_domains')->where('site_id', $site->site_id)->update(['is_primary' => false]);
                $remove = $this->table('wl_site_domains')->where('site_id', $site->site_id);
                if ($names !== []) $remove->whereNotIn('domain', $names);
                $remove->delete();
                foreach ($desired as $row) {
                    $query = $this->table('wl_site_domains')->where('site_id', $site->site_id)->where('domain', $row['domain']);
                    if ($query->exists()) $query->update(['is_primary' => $row['is_primary']]);
                    else $this->table('wl_site_domains')->insert($row + ['site_id' => $site->site_id, 'created_at' => $timestamp]);
                }
                $this->table('wl_sites')->where('site_id', $site->site_id)->update(['updated_at' => $timestamp]);
            }
            $this->assets()->write((string)$site->site_id, (int)$site->tenant_id, $desiredBrand, $beforeBrand);
            if ($beforeBrand !== $desiredBrand) $this->table('wl_sites')->where('site_id', $site->site_id)->update(['updated_at' => $timestamp]);
            $result = $writer->append((int)$site->tenant_id, (int)$site->site_id, $version, $operatorId,
                ($actorType === 'platform' ? 'platform-admin:' : 'white-label-admin:').$operatorId,
                '品牌配置域名更新：站点 '.$site->site_id);
            if ($current !== $desired || $result['changed'] || $beforeBrand !== $desiredBrand) {
                $audit(['tenant_id'=>(string)$site->tenant_id,'site_id'=>(string)$site->site_id,'before'=>$current,'after'=>$desired,
                    'before_brand'=>$beforeBrand, 'after_brand'=>$desiredBrand,
                    'snapshot_version'=>$result['config_version']], $timestamp);
                $result['changed'] = true;
                $result['updated_at'] = $timestamp;
            }
            return $result + ['revision'=>$this->revision($desired), 'brand_revision'=>SiteBrandAssets::revision($desiredBrand)];
        }, 3);
    }
}
