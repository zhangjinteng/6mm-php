<?php

declare(strict_types=1);

namespace SixMm\Shared\AccountChangeLogs;

final class AccountChangeLogListQuery
{
    private const SORTABLE_FIELDS = ['id', 'created_at', 'amount'];

    public function __construct(
        private int $pageSize = 20,
        private ?string $cursor = null,
        private string $keyword = '',
        private ?int $userType = null,
        private string $changeType = '',
        private string $symbol = '',
        private string $createdAtStart = '',
        private string $createdAtEndExclusive = '',
        private string $orderBy = 'id',
        private string $orderDirection = 'desc'
    ) {
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->cursor = ($cursor = trim((string) $this->cursor)) !== '' ? $cursor : null;
        $this->keyword = trim($this->keyword);
        $this->userType = $this->userType !== null && $this->userType > 0
            ? $this->userType
            : null;
        $this->changeType = strtolower(trim($this->changeType));
        $this->symbol = strtoupper(trim($this->symbol));
        $this->createdAtStart = trim($this->createdAtStart);
        $this->createdAtEndExclusive = trim($this->createdAtEndExclusive);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'id';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    public function userType(): ?int
    {
        return $this->userType;
    }

    public function changeType(): string
    {
        return $this->changeType;
    }

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function createdAtStart(): string
    {
        return $this->createdAtStart;
    }

    public function createdAtEndExclusive(): string
    {
        return $this->createdAtEndExclusive;
    }

    public function orderBy(): string
    {
        return $this->orderBy;
    }

    public function orderDirection(): string
    {
        return $this->orderDirection;
    }
}
