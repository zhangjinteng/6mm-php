<?php

declare(strict_types=1);

namespace SixMm\Shared\ContractSources;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

final class ContractSourceService
{
    public function __construct(private ConnectionInterface $database, private int $platformHomeSiteId) {}

    public function options(): array
    {
        if ($this->platformHomeSiteId <= 0) return ['merchants' => [], 'white_labels' => []];
        $public = $this->publicPrefix();
        $control = $this->controlPrefix();
        $merchants = $this->database->table($public . 'agents')
            ->where('home_site_id', $this->platformHomeSiteId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'agent_name', 'user_name'])
            ->map(static fn (object $agent): array => [
                'value' => (string) $agent->id,
                'label' => trim((string) $agent->agent_name)
                    ?: (trim((string) $agent->user_name) ?: (string) $agent->id),
            ])->all();
        $whiteLabels = $this->database->table($control . 'wl_tenants as tenants')
            ->join($control . 'wl_sites as sites', 'sites.tenant_id', '=', 'tenants.tenant_id')
            ->where('sites.site_id', '>', 0)->where('sites.site_id', '<>', $this->platformHomeSiteId)
            ->distinct()->orderBy('tenants.tenant_id')->get(['tenants.tenant_id', 'tenants.tenant_name'])
            ->map(static fn (object $tenant): array => [
                'value' => (string) $tenant->tenant_id,
                'label' => trim((string) $tenant->tenant_name) ?: (string) $tenant->tenant_id,
            ])->all();
        return ['merchants' => $merchants, 'white_labels' => $whiteLabels];
    }

    public function applyFilters(Builder $query, ContractSourceFilter $filter): void
    {
        if ($filter->sourceType === 'platform') {
            $query->where('users.agent_id', 0)->where(static function (Builder $query): void {
                $query->where('users.home_site_id', 0)->orWhereNull('users.home_site_id');
            });
        } elseif ($filter->isActive()) {
            if ($this->platformHomeSiteId <= 0) {
                $query->whereRaw('1 = 0');
                return;
            }
            if ($filter->sourceType === 'merchant' || $filter->agentId !== null) {
                $query->where('users.home_site_id', $this->platformHomeSiteId);
            } else {
                $query->where('users.home_site_id', '>', 0)
                    ->where('users.home_site_id', '<>', $this->platformHomeSiteId);
            }
        }
        if ($filter->agentId !== null) $query->where('users.agent_id', $filter->agentId);
        if ($filter->tenantId !== null) {
            $query->whereIn('users.home_site_id', $this->database->table($this->controlPrefix() . 'wl_sites')
                ->where('tenant_id', $filter->tenantId)->select('site_id'));
        }
    }

    public function matchingPlatformUserIds(ContractSourceFilter $filter): array
    {
        $query = $this->database->table($this->publicPrefix() . 'users as users')->whereNull('users.deleted_at');
        $this->applyFilters($query, $filter);
        return $query->orderBy('users.user_id')->pluck('users.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)->values()->all();
    }

    public function matchingAgentIds(ContractSourceFilter $filter): array
    {
        // Hedging subjects live in a separate database and have no user identity.
        if ($filter->sourceType === 'platform') return [0];
        if ($this->platformHomeSiteId <= 0 && $filter->isActive()) return [];
        $query = $this->database->table($this->publicPrefix() . 'agents as agents')
            ->where('agents.id', '>', 0)->whereNull('agents.deleted_at');
        if ($filter->sourceType === 'merchant' || $filter->agentId !== null) {
            $query->where('agents.home_site_id', $this->platformHomeSiteId);
        } elseif ($filter->sourceType === 'white_label') {
            $query->where('agents.home_site_id', '>', 0)
                ->where('agents.home_site_id', '<>', $this->platformHomeSiteId);
        }
        if ($filter->agentId !== null) $query->where('agents.id', $filter->agentId);
        if ($filter->tenantId !== null) {
            $query->whereIn('agents.home_site_id', $this->database->table($this->controlPrefix() . 'wl_sites')
                ->where('tenant_id', $filter->tenantId)->select('site_id'));
        }
        $ids = $query->orderBy('agents.id')->pluck('agents.id')->map(static fn ($id): int => (int) $id)->all();
        return $filter->isActive() ? $ids : array_merge([0], $ids);
    }

    public function appendToSubjects(array &$rows): void
    {
        if ($rows === []) return;
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['agent_id'] ?? 0), $rows
        ), static fn (int $id): bool => $id > 0)));
        $agents = $this->database->table($this->publicPrefix() . 'agents')
            ->whereIn('id', $ids)->whereNull('deleted_at')
            ->get(['id', 'home_site_id', 'agent_name', 'user_name'])->keyBy('id');
        $sites = $this->sites($agents->pluck('home_site_id')->map(static fn ($id): int => (int) $id)->all());
        foreach ($rows as &$row) {
            $id = $row['agent_id'] ?? null;
            $agent = $id === null ? null : $agents->get((string) $id);
            $identity = $id !== null && (string) $id === '0'
                ? (object) ['agent_id' => 0, 'home_site_id' => 0]
                : ($agent === null ? null : (object) ['agent_id' => $agent->id, 'home_site_id' => $agent->home_site_id]);
            $this->appendMetadata($row, $this->metadata($identity, $agents, $sites));
            $row['agent_name'] = trim((string) ($agent?->agent_name ?? ''))
                ?: (trim((string) ($agent?->user_name ?? '')) ?: '-');
        }
        unset($row);
    }

    public function appendToRows(array &$rows): void
    {
        if ($rows === []) return;
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['platform_user_id'] ?? ''), $rows
        ))));
        $users = $this->database->table($this->publicPrefix() . 'users')
            ->whereIn('user_id', $userIds)->whereNull('deleted_at')
            ->get(['user_id', 'agent_id', 'home_site_id'])->keyBy('user_id');
        $agentIds = [];
        $siteIds = [];
        foreach ($users as $user) {
            $siteId = (int) $user->home_site_id;
            if ($this->platformHomeSiteId <= 0 || $siteId <= 0) continue;
            if ($siteId === $this->platformHomeSiteId) {
                if ((int) $user->agent_id > 0) $agentIds[] = (int) $user->agent_id;
            } else {
                $siteIds[] = $siteId;
            }
        }
        $agents = $agentIds === [] ? collect() : $this->database->table($this->publicPrefix() . 'agents')
            ->whereIn('id', array_unique($agentIds))->whereNull('deleted_at')
            ->get(['id', 'agent_name', 'user_name'])->keyBy('id');
        $sites = $this->sites($siteIds);

        foreach ($rows as &$row) {
            $user = $users->get((string) ($row['platform_user_id'] ?? ''));
            $this->appendMetadata($row, $this->metadata($user, $agents, $sites));
        }
        unset($row);
    }

    private function sites(array $siteIds): Collection
    {
        $siteIds = array_values(array_unique(array_filter($siteIds,
            fn (int $id): bool => $this->platformHomeSiteId > 0 && $id > 0 && $id !== $this->platformHomeSiteId)));
        if ($siteIds === []) return collect();
        $control = $this->controlPrefix();
        return $this->database->table($control . 'wl_sites as sites')
            ->leftJoin($control . 'wl_tenants as tenants', 'tenants.tenant_id', '=', 'sites.tenant_id')
            ->whereIn('sites.site_id', $siteIds)
            ->get(['sites.site_id', 'tenants.tenant_id', 'tenants.tenant_name'])->keyBy('site_id');
    }

    private function metadata(?object $identity, Collection $agents, Collection $sites): array
    {
        $siteId = $identity?->home_site_id === null ? null : (int) $identity->home_site_id;
        $metadata = ['home_site_id' => $siteId, 'source_type' => null, 'source_id' => null, 'source_name' => '-'];
        if ($identity === null) return $metadata;
        $agentId = $identity->agent_id === null ? null : (int) $identity->agent_id;
        if ($agentId === 0 && ($siteId === 0 || $siteId === null)) {
            $metadata['source_type'] = 'platform';
            $metadata['source_id'] = '0';
            $metadata['source_name'] = '平台用户';
        } elseif ($this->platformHomeSiteId > 0 && $siteId > 0) {
            if ($siteId === $this->platformHomeSiteId) {
                $metadata['source_type'] = 'merchant';
                $metadata['source_id'] = $agentId > 0 ? (string) $agentId : null;
                $agent = $agents->get($agentId);
                $metadata['source_name'] = trim((string) ($agent?->agent_name ?? ''))
                    ?: (trim((string) ($agent?->user_name ?? '')) ?: '-');
            } else {
                $metadata['source_type'] = 'white_label';
                $site = $sites->get($siteId);
                $metadata['source_id'] = $site?->tenant_id === null ? null : (string) $site->tenant_id;
                $metadata['source_name'] = trim((string) ($site?->tenant_name ?? '')) ?: '-';
            }
        }
        return $metadata;
    }

    private function appendMetadata(array &$row, array $metadata): void
    {
        $metadata['agent_name_parse'] = $metadata['source_name'];
        $row = array_merge($row, $metadata);
        if (isset($row['user']) && is_array($row['user'])) $row['user'] = array_merge($row['user'], $metadata);
    }

    private function publicPrefix(): string
    {
        return $this->database->getDriverName() === 'pgsql' ? 'public.' : '';
    }

    private function controlPrefix(): string
    {
        return $this->database->getDriverName() === 'pgsql' ? 'control_plane.' : '';
    }
}
