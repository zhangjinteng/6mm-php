<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;

interface AgreementTemplateReplication
{
    /** Called only after authorized publication, inside the same source transaction. */
    public function enqueueTemplate(Closure $table, int $template, bool $deliverAfterCommit = true): void;
}
