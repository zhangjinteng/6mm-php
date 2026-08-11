<?php

declare(strict_types=1);

namespace SixMm\Shared\Liquidations;

interface LiquidationTradeQueryExecutor
{
    /** @return array<int, array<string, mixed>> */
    public function select(string $sql): array;
}
