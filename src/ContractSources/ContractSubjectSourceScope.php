<?php

declare(strict_types=1);

namespace SixMm\Shared\ContractSources;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

final class ContractSubjectSourceScope implements UserDataScope
{
    public function __construct(private UserDataScope $base, private array $agentIds) {}

    public function apply(Builder $query, string $agentColumn): void
    {
        $this->base->apply($query, $agentColumn);
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->agentIds), static fn (int $id): bool => $id >= 0)));
        $query->whereIn($agentColumn, $ids);
    }
}
