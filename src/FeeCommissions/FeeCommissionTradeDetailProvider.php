<?php

declare(strict_types=1);

namespace SixMm\Shared\FeeCommissions;

interface FeeCommissionTradeDetailProvider
{
    /**
     * Fill trade fields that are missing from the relational commission snapshot.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $rows): array;
}
