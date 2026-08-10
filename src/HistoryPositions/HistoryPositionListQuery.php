<?php

declare(strict_types=1);

namespace SixMm\Shared\HistoryPositions;

final class HistoryPositionListQuery
{
    private const SORTABLE_FIELDS = [
        'position_id',
        'symbol',
        'margin_mode',
        'position_side',
        'leverage',
        'quantity',
        'entry_price',
        'created_at',
    ];

    private const ALLOWED_STATUSES = [2, 3, 4];

    /**
     * @param array<int, int|string> $statuses
     */
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $userType = null,
        private string $symbol = '',
        private ?int $marginMode = null,
        private string $positionSide = '',
        private string $tradeSide = '',
        private ?int $leverage = null,
        private ?int $triggerMode = null,
        private array $statuses = [],
        private string $closedAtStart = '',
        private string $closedAtEnd = '',
        private string $orderBy = '',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->userType = in_array($this->userType, [1, 2, 3], true)
            ? $this->userType
            : null;
        $this->symbol = strtoupper(trim($this->symbol));
        $this->marginMode = in_array($this->marginMode, [1, 2], true)
            ? $this->marginMode
            : null;

        $side = strtolower(trim($this->positionSide));
        $this->positionSide = in_array($side, ['long', 'short'], true) ? $side : '';
        $tradeSide = strtolower(trim($this->tradeSide));
        $this->tradeSide = in_array($tradeSide, ['buy', 'sell'], true) ? $tradeSide : '';
        $this->leverage = $this->leverage !== null && $this->leverage > 0
            ? $this->leverage
            : null;
        $this->triggerMode = in_array($this->triggerMode, [1, 2, 3, 4], true)
            ? $this->triggerMode
            : null;
        $this->statuses = array_values(array_unique(array_intersect(
            self::ALLOWED_STATUSES,
            array_map(static fn ($status): int => (int) $status, $this->statuses)
        )));
        $this->closedAtStart = trim($this->closedAtStart);
        $this->closedAtEnd = trim($this->closedAtEnd);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'closed_at';
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

    public function tradeSide(): string
    {
        return $this->tradeSide;
    }

    public function triggerMode(): ?int
    {
        return $this->triggerMode;
    }

    /** @return array<int, int> */
    public function statuses(): array
    {
        return $this->statuses === [] ? self::ALLOWED_STATUSES : $this->statuses;
    }

    public function closedAtStart(): string
    {
        return $this->closedAtStart;
    }

    public function closedAtEnd(): string
    {
        return $this->closedAtEnd;
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
