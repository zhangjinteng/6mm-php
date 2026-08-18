<?php

declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

final class HandlingFeeConfigListQuery
{
    public function __construct(
        private int $agentId = 0,
        private int $page = 1,
        private int $pageSize = 20
    ) {
        $this->agentId = max(0, $this->agentId);
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
    }

    public function agentId(): int
    {
        return $this->agentId;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }
}
