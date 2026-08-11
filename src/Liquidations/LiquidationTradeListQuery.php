<?php

declare(strict_types=1);

namespace SixMm\Shared\Liquidations;

final class LiquidationTradeListQuery
{
    public function __construct(
        private int $positionId,
        private int $page = 1,
        private int $pageSize = 15
    ) {
        $this->positionId = max(0, $this->positionId);
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
    }

    public function positionId(): int { return $this->positionId; }
    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
}
