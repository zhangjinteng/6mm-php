<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

final class HedgingMonitorQuery
{
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private string $status = '',
        private string $globalEnabled = '',
        private string $symbolEnabled = ''
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = strtoupper(trim($this->keyword));
        $this->status = strtolower(trim($this->status));
        $this->globalEnabled = $this->normalizeSwitch($this->globalEnabled);
        $this->symbolEnabled = $this->normalizeSwitch($this->symbolEnabled);
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

    public function globalEnabled(): string
    {
        return $this->globalEnabled;
    }

    public function symbolEnabled(): string
    {
        return $this->symbolEnabled;
    }

    private function normalizeSwitch(string $value): string
    {
        $value = trim($value);

        return in_array($value, ['0', '1'], true) ? $value : '';
    }
}
