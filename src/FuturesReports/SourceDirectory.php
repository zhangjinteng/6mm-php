<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;

/** Resolves immutable site origins, never treats site_id as tenant_id. */
final class SourceDirectory
{
    public function __construct(private ConnectionInterface $database) {}

    public function load(array $userIds = []): array
    {
        $pg = $this->database->getDriverName() === 'pgsql';
        $public = $pg ? 'public.' : '';
        $control = $pg ? 'control_plane.' : '';
        $agents = [];
        // Select display/origin fields only; do not load merchant credentials.
        // Laravel 8's schema introspection mishandles public.agents when the configured
        // search_path contains only control_plane; query the qualified catalog directly.
        $columns = $pg
            ? $this->database->table('information_schema.columns')->where('table_schema', 'public')
                ->where('table_name', 'agents')->pluck('column_name')->all()
            : $this->database->getSchemaBuilder()->getColumnListing('agents');
        $displayFields = array_values(array_intersect(['id', 'home_site_id', 'agent_code', 'agent_name', 'user_name'], $columns));
        foreach ($this->database->table($public . 'agents')->where('pid', 0)->whereNull('deleted_at')->get($displayFields) as $agent) {
            $agents[(string) $agent->id] = [
                'source_type' => 'merchant', 'source_id' => (string) $agent->id,
                'source_code' => (string) (($agent->agent_code ?? '') ?: $agent->id),
                'source_name' => (string) (($agent->agent_name ?? '') ?: (($agent->user_name ?? '') ?: $agent->id)),
                'site_id' => (string) ($agent->home_site_id ?? ''),
            ];
        }
        $sites = $this->database->table($control . 'wl_sites')->pluck('tenant_id', 'site_id')->all();
        $tenants = [];
        foreach ($this->database->table($control . 'wl_tenants')->get(['tenant_id', 'tenant_name']) as $tenant) {
            $tenants[(string) $tenant->tenant_id] = [
                'source_type' => 'tenant', 'source_id' => (string) $tenant->tenant_id,
                'source_code' => (string) $tenant->tenant_id, 'source_name' => (string) $tenant->tenant_name,
            ];
        }
        $users = [];
        foreach (array_chunk(array_values(array_unique($userIds)), 1000) as $chunk) {
            foreach ($this->database->table($public . 'users')->whereIn('user_id', $chunk)->get(['user_id', 'home_site_id']) as $user) {
                $users[(string) $user->user_id] = (string) ($user->home_site_id ?? '');
            }
        }
        return compact('agents', 'sites', 'tenants', 'users');
    }
}
