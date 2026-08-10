<?php

declare(strict_types=1);

namespace SixMm\Shared\HistoryOrders;

final class HistoryOrderListQuery
{
    private const SORTABLE_FIELDS = [
        'user_id',
        'order_id',
        'order_face_value',
        'quantity',
        'price',
        'created_at',
    ];

    private const FINAL_STATUSES = [3, 4, 5, 6];

    /**
     * @param array<int, int|string> $orderStatuses
     */
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $userType = null,
        private string $symbol = '',
        private string $orderType = '',
        private ?int $marginMode = null,
        private string $side = '',
        private ?int $leverage = null,
        private ?bool $reduceOnly = null,
        private ?bool $makerOnly = null,
        private array $orderStatuses = [],
        private string $createdAtStart = '',
        private string $createdAtEnd = '',
        private string $orderBy = 'created_at',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->userType = in_array($this->userType, [1, 2, 3], true)
            ? $this->userType
            : null;
        $this->symbol = strtoupper(trim($this->symbol));

        $orderType = strtolower(trim($this->orderType));
        $this->orderType = in_array($orderType, ['limit', 'market'], true)
            ? $orderType
            : '';
        $this->marginMode = in_array($this->marginMode, [1, 2], true)
            ? $this->marginMode
            : null;

        $side = strtolower(trim($this->side));
        $this->side = in_array($side, ['buy', 'sell'], true) ? $side : '';
        $this->leverage = $this->leverage !== null && $this->leverage > 0
            ? $this->leverage
            : null;
        $this->orderStatuses = array_values(array_unique(array_intersect(
            self::FINAL_STATUSES,
            array_map(static fn ($status): int => (int) $status, $this->orderStatuses)
        )));
        $this->createdAtStart = trim($this->createdAtStart);
        $this->createdAtEnd = trim($this->createdAtEnd);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'created_at';
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

    public function orderType(): string
    {
        return $this->orderType;
    }

    public function marginMode(): ?int
    {
        return $this->marginMode;
    }

    public function side(): string
    {
        return $this->side;
    }

    public function leverage(): ?int
    {
        return $this->leverage;
    }

    public function reduceOnly(): ?bool
    {
        return $this->reduceOnly;
    }

    public function makerOnly(): ?bool
    {
        return $this->makerOnly;
    }

    /** @return array<int, int> */
    public function orderStatuses(): array
    {
        return $this->orderStatuses;
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

    /**
     * @param array<int, int|string> $currentUserTypeUserIds
     * @return array<string, mixed>
     */
    public function sourceFilters(array $currentUserTypeUserIds = []): array
    {
        $normalizedFallbackIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $currentUserTypeUserIds
        ), static fn (int $id): bool => $id > 0)));
        sort($normalizedFallbackIds);

        return [
            'page_no' => $this->page,
            'page_size' => $this->pageSize,
            'keyword' => $this->keyword,
            'user_type' => $this->userType,
            'current_user_type_user_ids' => $normalizedFallbackIds,
            'symbol' => $this->symbol,
            'order_type' => $this->orderType,
            'margin_mode' => $this->marginMode,
            'side' => $this->side,
            'leverage' => $this->leverage,
            'reduce_only' => $this->reduceOnly,
            'maker_only' => $this->makerOnly,
            'order_status' => $this->orderStatuses,
            'start_time' => $this->createdAtStart,
            'end_time' => $this->createdAtEnd,
            'order_by' => $this->orderBy,
            'order_dir' => $this->orderDirection,
        ];
    }
}
