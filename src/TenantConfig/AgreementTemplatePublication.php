<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;
use Illuminate\Container\Container;

/** Shared hook; never changes editor permissions or creates site agreements. */
final class AgreementTemplatePublication
{
    public static function sync(Closure $table, int $template): void
    {
        $container = Container::getInstance();
        if ($container->bound(AgreementTemplateReplication::class)) {
            $container->make(AgreementTemplateReplication::class)->enqueueTemplate($table, $template);
        }
    }
}
