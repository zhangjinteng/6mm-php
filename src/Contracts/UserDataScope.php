<?php

declare(strict_types=1);

namespace SixMm\Shared\Contracts;

use Illuminate\Database\Query\Builder;

interface UserDataScope
{
    public function apply(Builder $query, string $agentColumn): void;
}
