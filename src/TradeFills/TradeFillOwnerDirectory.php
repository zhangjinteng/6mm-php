<?php

declare(strict_types=1);

namespace SixMm\Shared\TradeFills;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\ContractSources\ContractSourceFilter;

final class TradeFillOwnerDirectory
{
    public function __construct(private ConnectionInterface $db) {}

    /** Total-admin categories are CK predicates; directory mappings only narrow a selected name. */
    public function scope(ContractSourceFilter $filter): TradeFillOwnerScope
    {
        if (!$filter->isActive()) return TradeFillOwnerScope::all();
        $owners = [];
        if ($filter->sourceType === 'platform') return new TradeFillOwnerScope([[0, 0]]);
        if ($filter->sourceType === 'merchant' && $filter->agentId === null) return TradeFillOwnerScope::category('merchant');
        if ($filter->sourceType === 'white_label' && $filter->tenantId === null) return TradeFillOwnerScope::category('white_label');
        if ($filter->sourceType !== 'white_label') {
            $query = $this->db->table('agents')->whereNull('deleted_at')->where('home_site_id', '>', 0);
            if ($filter->agentId !== null) {
                $selected = $this->db->table('agents')->whereNull('deleted_at')->where('id', $filter->agentId)->first();
                $query->where('id', $selected ? ((int) ($selected->pid ?? 0) ?: (int) $selected->id) : -1);
            } else $query->where('pid', 0);
            foreach ($query->get(['id', 'home_site_id']) as $agent) $owners[] = [(int) $agent->home_site_id, (int) $agent->id];
        }
        if ($filter->sourceType === 'white_label') {
            $sites = $this->db->table($this->control() . 'wl_sites')->where('site_id', '>', 0);
            if ($filter->tenantId !== null) $sites->where('tenant_id', $filter->tenantId);
            foreach ($sites->get(['site_id']) as $site) {
                $owners[] = [(int) $site->site_id, 0];
            }
        }
        return new TradeFillOwnerScope($owners);
    }

    /** Display metadata follows the CK owner even after users change ownership. */
    public function appendToRows(array &$rows): void
    {
        if ($rows === []) return;
        $agentIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['agent_id'] ?? 0), $rows))));
        $siteIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['home_site_id'] ?? 0), $rows))));
        $agents = $agentIds === [] ? collect() : $this->db->table('agents')->whereIn('id', $agentIds)->get(['id', 'home_site_id', 'agent_name', 'user_name'])->keyBy('id');
        $sites = $siteIds === [] ? collect() : $this->db->table($this->control() . 'wl_sites as s')
            ->leftJoin($this->control() . 'wl_tenants as t', 's.tenant_id', '=', 't.tenant_id')
            ->whereIn('s.site_id', $siteIds)->get(['s.site_id', 's.tenant_id', 't.tenant_name'])->keyBy('site_id');
        foreach ($rows as &$row) {
            $site = (int) ($row['home_site_id'] ?? -1); $agent = (int) ($row['agent_id'] ?? -1);
            $metadata = ['source_type' => null, 'source_id' => null, 'source_name' => '-'];
            if ($site === 0 && $agent === 0) $metadata = ['source_type' => 'platform', 'source_id' => '0', 'source_name' => '平台用户'];
            elseif ($site > 0 && $agent === 0) {
                $entry = $sites->get($site);
                $metadata = ['source_type' => 'white_label', 'source_id' => isset($entry->tenant_id) ? (string) $entry->tenant_id : null,
                    'source_name' => $entry->tenant_name ?? '-'];
            } elseif ($site > 0 && $agent > 0) {
                $entry = $agents->get($agent);
                $metadata = ['source_type' => 'merchant', 'source_id' => (string) $agent,
                    'source_name' => $entry && (int) $entry->home_site_id === $site ? (trim((string) $entry->agent_name) ?: trim((string) $entry->user_name) ?: '-') : '-'];
            }
            $metadata['agent_name_parse'] = $metadata['source_name'];
            $row = array_merge($row, $metadata);
            if (isset($row['user'])) $row['user'] = array_merge($row['user'], $metadata);
        }
        unset($row);
    }

    private function control(): string { return $this->db->getDriverName() === 'pgsql' ? 'control_plane.' : ''; }
}
