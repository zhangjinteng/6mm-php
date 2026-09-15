<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

final class HedgingSymbolConfigQuery
{
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private string $exchange = '',
        private ?int $accountId = null,
        private string $hedgeUnit = '',
        private string $enabled = ''
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = strtoupper(trim($this->keyword));
        $this->exchange = trim($this->exchange);
        $this->accountId = $this->accountId !== null && $this->accountId > 0 ? $this->accountId : null;
        $this->hedgeUnit = in_array($this->hedgeUnit, ['base', 'usdt'], true) ? $this->hedgeUnit : '';
        $this->enabled = in_array($this->enabled, ['0', '1'], true) ? $this->enabled : '';
    }

    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function keyword(): string { return $this->keyword; }
    public function exchange(): string { return $this->exchange; }
    public function accountId(): ?int { return $this->accountId; }
    public function hedgeUnit(): string { return $this->hedgeUnit; }
    public function enabled(): string { return $this->enabled; }
}
