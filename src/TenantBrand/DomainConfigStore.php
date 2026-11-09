<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\ConnectionInterface;
use Carbon\CarbonImmutable;
use SixMm\Shared\TenantConfig\ConfigSnapshotWriter;

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

    private function revision(array $domains): string
    {
        return hash('sha256', json_encode($domains, JSON_THROW_ON_ERROR));
    }

    public function detail(array $input): array
    {
        return $this->db->transaction(function () use ($input): array {
            if ($this->db->getDriverName() === 'pgsql') $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $tenant = $this->table('wl_tenants')->where('tenant_id', $input['tenant_id'])->first();
            if (!$tenant) $this->reject('白标不存在');
            $sites = $this->table('wl_sites')->where('tenant_id', $tenant->tenant_id)
                ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->orderBy('site_id')->get();
            return ['tenant_id' => (string) $tenant->tenant_id, 'tenant_name' => $tenant->tenant_name,
                'sites' => $sites->map(function ($site): array {
                    $domains = $this->domains((string) $site->site_id);
                    return ['site_id' => (string) $site->site_id, 'site_name' => $site->site_name,
                        'domains' => $domains, 'revision' => $this->revision($domains), 'updated_at' => $site->updated_at ?? null];
                })->all()];
        });
    }

    public function save(array $input, int $operatorId, callable $audit, string $actorType = 'platform'): array
    {
        if ($operatorId <= 0 || !in_array($actorType, ['platform', 'white_label'], true)) throw new \InvalidArgumentException('Invalid brand actor');
        if (!is_string($input['expected_revision'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['expected_revision'])) $this->reject('域名版本参数不正确');
        $desired = DomainConfigValidator::normalize($input['domains'] ?? null);
        return $this->db->transaction(function () use ($input, $operatorId, $desired, $audit, $actorType): array {
            if ($this->db->getDriverName() === 'pgsql') {
                $this->db->statement("SET LOCAL lock_timeout = '5s'");
                $this->db->statement("SET LOCAL statement_timeout = '15s'");
                // Use the business publisher's lock order so unrelated configuration edits are preserved.
                $this->db->statement('LOCK TABLE control_plane.wl_config_snapshots, control_plane.wl_tenants, control_plane.wl_sites, control_plane.wl_site_modules, control_plane.wl_site_domains, control_plane.wl_site_config_domains IN SHARE ROW EXCLUSIVE MODE');
            }
            $site = $this->table('wl_sites')->where('site_id', $input['site_id'])->where('tenant_id', $input['tenant_id'])
                ->when($this->enabledSitesOnly, fn ($q) => $q->where('status', 'enabled'))->lockForUpdate()->first();
            if (!$site) $this->reject('站点不属于当前白标或已不存在');
            $timestamp = CarbonImmutable::now()->format('Y-m-d H:i:s.uP');
            $current = $this->domains((string) $site->site_id);
            if (!hash_equals($this->revision($current), $input['expected_revision'])) $this->reject('域名已被其他操作员修改，请重新加载后再保存');
            foreach ($desired as $row) {
                if ($this->table('wl_site_domains')->where('domain', $row['domain'])->where('site_id', '<>', $site->site_id)->exists()) {
                    $this->reject('域名已被其他站点使用');
                }
            }
            $names = array_column($desired, 'domain');
            $map = $this->table('wl_site_config_domains');
            if ($current === $desired && PublishedDomainMap::names($map, (string) $site->site_id) === $names) return ['changed' => false];
            [$snapshot, $payload, $target] = $this->snapshot((string) $site->tenant_id, (string) $site->site_id, $current);
            foreach ($payload->sites as $publishedSite) {
                if ((string) $publishedSite->siteId === (string) $site->site_id) continue;
                if (array_intersect(array_column($desired, 'domain'), $publishedSite->domains) !== []) {
                    $this->reject('域名已在其他站点的发布配置中使用，请先核对配置');
                }
            }
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
            $oldNames = array_column($current, 'domain');
            $publishedVersion = (string) $snapshot->snapshot_version;
            if ($oldNames !== $names) {
                $target->domains = $names;
                $publishedVersion = ConfigSnapshotWriter::append(
                    $this->table('wl_config_snapshots'), $snapshot, $payload,
                    ($actorType === 'platform' ? 'platform-admin:' : 'white-label-admin:') . $operatorId,
                    '品牌配置域名更新：站点 ' . $site->site_id, $timestamp, $this->reject(...),
                );
            }
            PublishedDomainMap::sync($map, (string) $site->site_id, $names);
            $audit(['tenant_id' => (string) $site->tenant_id, 'site_id' => (string) $site->site_id,
                'before' => $current, 'after' => $desired, 'snapshot_version' => $publishedVersion], $timestamp);
            return ['changed' => true, 'snapshot_version' => $publishedVersion, 'revision' => $this->revision($desired), 'updated_at' => $timestamp];
        }, 3);
    }

    private function snapshot(string $tenantId, string $siteId, array $current): array
    {
        $snapshot = $this->table('wl_config_snapshots')->orderByDesc('snapshot_version')->first();
        if (!$snapshot || !hash_equals($snapshot->payload_sha256, hash('sha256', $snapshot->payload))) $this->reject('发布快照缺失或校验失败，请联系管理员');
        try { $payload = json_decode($snapshot->payload, false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { $this->reject('发布快照格式错误'); }
        if (!is_object($payload) || (int) $snapshot->schema_version !== 4 || ($payload->schemaVersion ?? null) !== 4
            || ($payload->snapshotVersion ?? null) !== (int) $snapshot->snapshot_version || !is_array($payload->sites ?? null)) {
            $this->reject('发布快照版本不受支持');
        }
        $seen = [];
        foreach ($payload->sites as $entry) {
            if (!is_object($entry) || !is_int($entry->siteId ?? null) || $entry->siteId <= 0
                || !is_int($entry->tenantId ?? null) || $entry->tenantId <= 0 || isset($seen[$entry->siteId])
                || !is_array($entry->domains ?? null) || count(array_filter($entry->domains, 'is_string')) !== count($entry->domains)) {
                $this->reject('发布快照站点结构无效');
            }
            $seen[$entry->siteId] = true;
        }
        $matches = array_filter($payload->sites, fn ($s) => is_object($s) && (string) ($s->siteId ?? '') === $siteId);
        if (count($matches) !== 1) $this->reject('站点尚未进入发布快照，请先完成站点初始化');
        $target = array_values($matches)[0];
        if ((string) ($target->tenantId ?? '') !== $tenantId || !is_array($target->domains ?? null)
            || count(array_filter($target->domains, 'is_string')) !== count($target->domains)) $this->reject('快照站点归属或域名格式不正确');
        $published = $target->domains;
        sort($published);
        if ($published !== array_column($current, 'domain')) $this->reject('域名表与发布快照不一致，请先核对配置');
        return [$snapshot, $payload, $target];
    }
}
