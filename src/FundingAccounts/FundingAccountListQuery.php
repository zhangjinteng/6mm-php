<?php

declare(strict_types=1);

namespace SixMm\Shared\FundingAccounts;

final class FundingAccountListQuery
{
    private string $startTime;
    private string $endTimeExclusive;

    private const SORTABLE_FIELDS = [
        'currency',
        'account_balance',
        'available_balance',
        'frozen_balance',
        'last_changed_at',
    ];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        ?string $startTime = null,
        ?string $endTimeExclusive = null,
        private string $orderBy = 'last_changed_at',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->startTime = trim((string) $startTime);
        $this->endTimeExclusive = trim((string) $endTimeExclusive);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'last_changed_at';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
    }

    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function keyword(): string { return $this->keyword; }
    public function startTime(): string { return $this->startTime; }
    public function endTimeExclusive(): string { return $this->endTimeExclusive; }
    public function orderBy(): string { return $this->orderBy; }
    public function orderDirection(): string { return $this->orderDirection; }
}
