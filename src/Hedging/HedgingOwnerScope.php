<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

/** Exact hedge owner, independent of ordinary user attribution. */
final class HedgingOwnerScope implements UserDataScope
{
    public function __construct(private string $ownerType, private int $ownerId)
    {
        if (!in_array($ownerType, ['merchant', 'tenant'], true)
            || $ownerId < 0 || ($ownerType === 'tenant' && $ownerId === 0)) {
            throw new \InvalidArgumentException('Invalid hedging owner');
        }
    }

    public function apply(Builder $query, string $agentColumn): void
    {
        $prefix = str_contains($agentColumn, '.') ? substr($agentColumn, 0, strrpos($agentColumn, '.') + 1) : '';
        $query->where($prefix . 'owner_type', $this->ownerType)
            ->where($prefix . 'owner_id', $this->ownerId);
    }
}
