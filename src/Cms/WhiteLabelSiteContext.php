<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

/** Site discovery is derived from the authenticated tenant, never a request tenant ID. */
final class WhiteLabelSiteContext
{
    private ?Collection $sites = null;

    public function __construct(private Connection $db, private string $tenantId) {}

    public function sites(): Collection
    {
        return $this->sites ??= $this->db->table('control_plane.wl_sites as site')
            ->leftJoin('cms.spaces as space', static function ($join): void {
                $join->on('space.home_site_id', '=', 'site.site_id')->where('space.space_type', 'site');
            })->where('site.tenant_id', $this->tenantId)->where('site.status', 'enabled')
            ->orderBy('site.site_id')->get(['site.site_id', 'site.site_name', 'space.id as space_id']);
    }

    public function context(?string $spaceId = null): array
    {
        $selected = $spaceId === null ? $this->sites()->first()
            : $this->sites()->first(static fn ($site) => $site->space_id !== null && (string) $site->space_id === $spaceId);
        return [
            'sites' => $this->sites()->map(static fn ($site) => [
                'site_id' => (string) $site->site_id, 'label' => $site->site_name,
                'space_id' => $site->space_id === null ? null : (string) $site->space_id,
            ])->all(),
            'site_id' => $selected === null ? null : (string) $selected->site_id,
            'space_id' => $selected?->space_id === null ? null : (string) $selected->space_id,
        ];
    }
}
