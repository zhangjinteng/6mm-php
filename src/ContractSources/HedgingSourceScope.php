<?php

declare(strict_types=1);

namespace SixMm\Shared\ContractSources;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

/** Source filters for owner-aware hedge tables (not users). */
final class HedgingSourceScope implements UserDataScope
{
    public function __construct(private UserDataScope $base, private ContractSourceFilter $filter)
    {
    }

    public function apply(Builder $query, string $agentColumn): void
    {
        $this->base->apply($query, $agentColumn);
        $prefix = str_contains($agentColumn, '.') ? substr($agentColumn, 0, strrpos($agentColumn, '.') + 1) : '';
        if ($this->filter->sourceType === 'platform') {
            $query->where($prefix . 'owner_type', 'merchant')->where($prefix . 'owner_id', 0);
        } elseif ($this->filter->sourceType === 'white_label') {
            $query->where($prefix . 'owner_type', 'tenant')->where($prefix . 'owner_id', '>', 0);
            if ($this->filter->tenantId !== null) $query->where($prefix . 'owner_id', $this->filter->tenantId);
        } elseif ($this->filter->sourceType === 'merchant' || $this->filter->agentId !== null) {
            $query->where($prefix . 'owner_type', 'merchant')->where($prefix . 'owner_id', '>', 0);
            if ($this->filter->agentId !== null) $query->where($prefix . 'owner_id', $this->filter->agentId);
        }
    }
}
