<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Content ownership is a space dimension, never a submitting user's source. */
final class CmsSourceScope
{
    private array $spaces;
    private array $filters;
    private array $creatorNames = [];
    public bool $browse;
    public ?object $selected = null;

    public function __construct(Connection $db, array $input, string $action, string $module)
    {
        $this->filters = Validator::make($input, [
            'cms_source_type' => 'nullable|in:platform,white_label',
            'cms_tenant_id' => 'nullable|integer|min:1',
            'space_id' => 'nullable|integer|min:1',
        ])->validate();
        $this->spaces = $db->table('cms.spaces as s')
            ->leftJoin('control_plane.wl_sites as ws', 'ws.site_id', '=', 's.home_site_id')
            ->leftJoin('control_plane.wl_tenants as wt', 'wt.tenant_id', '=', 'ws.tenant_id')
            ->where(function (Builder $q): void {
                $q->where(function (Builder $q): void { $q->where('s.space_type', 'template')->where('s.template_key', 'default'); })
                    ->orWhere('s.space_type', 'site');
            })->select(['s.*', 'wt.tenant_id as cms_tenant_id', 'wt.tenant_name as cms_tenant_name'])
            ->get()->keyBy('id')->all();
        // Resolve names in one batch; never use updated_by_name (the last editor)
        // or the space creator as a substitute for the record's original creator.
        foreach ($db->table('public.admin')->get(['id', 'name', 'account']) as $admin) {
            $this->creatorNames[(string) $admin->id] = trim((string) $admin->name)
                ?: (trim((string) $admin->account) ?: '—');
        }
        $this->browse = in_array($action, ['categories', 'articles', 'courses', 'metadata', 'listing'], true);
        $id = $this->filters['space_id'] ?? null;
        if (!$this->browse && !empty($input['id'])) {
            $identity = Validator::make($input, ['id' => 'required|integer|min:1'])->validate();
            $table = $module === 'feedback' ? 'feedback' : (stripos($action, 'categor') !== false ? 'categories' : 'contents');
            $record = $db->table('cms.' . $table)->where('id', $identity['id'])->whereNull('deleted_at');
            if ($module !== 'feedback') $record->where('module', $module);
            $row = $record->first(['space_id']);
            if (!$row || ($id !== null && (string) $row->space_id !== (string) $id)) $this->fail();
            $id = $row->space_id;
            $this->browse = false;
        }
        if ($id !== null) {
            $this->selected = $this->spaces[$id] ?? null;
            if ($this->selected === null) $this->fail();
        }
    }

    public function apply(Builder $query, string $column = 'space_id'): Builder
    {
        return $query->whereIn($column, $this->ids());
    }

    public function ids(): array
    {
        return array_keys(array_filter($this->spaces, function (object $space): bool {
            if ($this->selected !== null && (string) $space->id !== (string) $this->selected->id) return false;
            $type = $space->space_type === 'template' ? 'platform' : 'white_label';
            if (!empty($this->filters['cms_source_type']) && $type !== $this->filters['cms_source_type']) return false;
            return empty($this->filters['cms_tenant_id']) || ($type === 'white_label'
                && (string) $space->cms_tenant_id === (string) $this->filters['cms_tenant_id']);
        }));
    }

    public function metadata(array $row): array
    {
        $space = $this->spaces[$row['space_id'] ?? ''] ?? null;
        return $row + [
            'cms_source_type' => $space === null ? null : ($space->space_type === 'template' ? 'platform' : 'white_label'),
            'cms_source_name' => $space === null ? '—' : ($space->space_type === 'template'
                ? (($row['created_by_type'] ?? null) === 'platform' ? ($this->creatorNames[(string) ($row['created_by_id'] ?? '')] ?? '—') : '—')
                : ($space->cms_tenant_name ?: '—')),
            'cms_tenant_id' => $space?->cms_tenant_id === null ? null : (string) $space->cms_tenant_id,
        ];
    }

    public function options(): array
    {
        $tenants = [];
        foreach ($this->spaces as $space) if ($space->space_type === 'site' && $space->cms_tenant_id !== null) {
            $tenants[(string) $space->cms_tenant_id] = ['value' => (string) $space->cms_tenant_id, 'label' => $space->cms_tenant_name ?: (string) $space->cms_tenant_id];
        }
        usort($tenants, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        return array_values($tenants);
    }

    private function fail(): void
    {
        throw ValidationException::withMessages(['id' => '记录不存在或不属于所选内容空间']);
    }
}
