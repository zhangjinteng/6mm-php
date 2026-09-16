<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

final class HedgingSubjectConfigQuery
{
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $enabled = '',
        private string $status = ''
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->enabled = in_array($this->enabled, ['0', '1'], true) ? $this->enabled : '';
        $this->status = in_array($this->status, ['running', 'disabled', 'pending_config', 'account_abnormal'], true)
            ? $this->status
            : '';
    }

    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function enabled(): string { return $this->enabled; }
    public function status(): string { return $this->status; }
}
