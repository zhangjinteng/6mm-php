<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

/** Explicit site-direct restriction. Never inferred from an unrestricted/null merchant filter. */
interface GridDirectCustomerScope extends GridScope
{
    public function directCustomersOnly(): bool;
}
