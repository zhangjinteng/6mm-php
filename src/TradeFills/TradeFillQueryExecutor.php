<?php

declare(strict_types=1);

namespace SixMm\Shared\TradeFills;

interface TradeFillQueryExecutor
{
    /** @return array<int, array<string, mixed>> */
    public function select(string $sql): array;
}
