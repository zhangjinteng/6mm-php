<?php

declare(strict_types=1);

namespace SixMm\Shared\ContractSources;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

final class ContractSourceScope implements UserDataScope
{
    public function __construct(
        private UserDataScope $base,
        private ContractSourceService $sources,
        private ContractSourceFilter $filter
    ) {}

    public function apply(Builder $query, string $agentColumn): void
    {
        $this->base->apply($query, $agentColumn);
        $this->sources->applyFilters($query, $this->filter);
    }
}
