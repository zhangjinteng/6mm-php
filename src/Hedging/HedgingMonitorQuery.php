<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

final class HedgingMonitorQuery
{
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private string $status = ''
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = strtoupper(trim($this->keyword));
        $this->status = strtolower(trim($this->status));
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    public function status(): string
    {
        return $this->status;
    }
}
