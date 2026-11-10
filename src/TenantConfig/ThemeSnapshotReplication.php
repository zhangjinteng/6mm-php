<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;

interface ThemeSnapshotReplication
{
    /** Must participate in the source transaction using exactly this trusted table resolver. */
    public function enqueue(Closure $table, int $site): void;
}
