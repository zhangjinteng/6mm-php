<?php

declare(strict_types=1);

namespace SixMm\Shared\CurrentPositions;

final class CurrentPositionListQuery
{
    private const SORTABLE_FIELDS = [
        'position_id',
        'user_type',
        'symbol',
        'margin_mode',
        'position_side',
        'leverage',
        'quantity',
        'entry_price',
        'created_at',
    ];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $userType = null,
        private string $symbol = '',
        private ?int $marginMode = null,
        private string $positionSide = '',
        private ?int $leverage = null,
        private string $createdAtStart = '',
        private string $createdAtEnd = '',
        private string $orderBy = 'position_id',
        private string $orderDirection = 'desc',
        private bool $largeContract = false,
        private bool $liquidationWarning = false
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->userType = $this->positiveIntegerOrNull($this->userType);
        $this->symbol = strtoupper(trim($this->symbol));
        $this->marginMode = in_array($this->marginMode, [1, 2], true)
            ? $this->marginMode
            : null;
        $side = strtolower(trim($this->positionSide));
        $this->positionSide = in_array($side, ['long', 'short'], true) ? $side : '';
        $this->leverage = $this->positiveIntegerOrNull($this->leverage);
        $this->createdAtStart = trim($this->createdAtStart);
        $this->createdAtEnd = trim($this->createdAtEnd);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'position_id';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
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

    public function userType(): ?int
    {
        return $this->userType;
    }

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function marginMode(): ?int
    {
        return $this->marginMode;
    }

    public function positionSide(): string
    {
        return $this->positionSide;
    }

    public function leverage(): ?int
    {
        return $this->leverage;
    }

    public function createdAtStart(): string
    {
        return $this->createdAtStart;
    }

    public function createdAtEnd(): string
    {
        return $this->createdAtEnd;
    }

    public function orderBy(): string
    {
        return $this->orderBy;
    }

    public function orderDirection(): string
    {
        return $this->orderDirection;
    }

    public function largeContract(): bool
    {
        return $this->largeContract;
    }

    public function liquidationWarning(): bool
    {
        return $this->liquidationWarning;
    }

    private function positiveIntegerOrNull(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }
}
