<?php

declare(strict_types=1);

namespace SixMm\Shared\DataScope;

use Illuminate\Database\Query\Builder;
use SixMm\Shared\Contracts\UserDataScope;

/**
 * Explicit unrestricted scope for trusted platform-administration use only.
 */
final class AllUsersScope implements UserDataScope
{
    public function apply(Builder $query, string $agentColumn): void
    {
        // Intentionally unrestricted. Requiring this explicit type prevents an
        // omitted/nullable scope from accidentally widening a query.
    }
}
