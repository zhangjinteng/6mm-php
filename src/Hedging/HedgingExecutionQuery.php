<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

final class HedgingExecutionQuery
{
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private string $symbol = '',
        private string $side = '',
        private string $reason = '',
        private ?int $accountId = null,
        private string $status = ''
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = strtolower(trim($this->keyword));
        $this->symbol = trim($this->symbol);
        $this->side = strtoupper(trim($this->side));
        $this->reason = strtolower(trim($this->reason));
        $this->status = strtolower(trim($this->status));
        $this->accountId = $this->accountId !== null && $this->accountId > 0
            ? $this->accountId
            : null;
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

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function side(): string
    {
        return $this->side;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function accountId(): ?int
    {
        return $this->accountId;
    }

    public function status(): string
    {
        return $this->status;
    }
}
