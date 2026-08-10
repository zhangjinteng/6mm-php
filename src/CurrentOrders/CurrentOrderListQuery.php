<?php

declare(strict_types=1);

namespace SixMm\Shared\CurrentOrders;

final class CurrentOrderListQuery
{
    /**
     * @param array<int, int|string> $orderStatuses
     */
    public function __construct(
        private int $pageSize = 20,
        private ?string $cursor = null,
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
        private string $createdAtEnd = ''
    ) {
        $this->pageSize = min(100, max(1, $this->pageSize));
        $cursor = trim((string) $this->cursor);
        $this->cursor = $cursor === '' ? null : $cursor;
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
        $this->orderStatuses = array_values(array_unique(array_filter(array_map(
            static fn ($status): int => (int) $status,
            $this->orderStatuses
        ), static fn (int $status): bool => $status >= 0)));
        $this->createdAtStart = trim($this->createdAtStart);
        $this->createdAtEnd = trim($this->createdAtEnd);
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

    /** @return array<string, mixed> */
    public function sourceFilters(string $keyword = ''): array
    {
        return [
            'page_size' => $this->pageSize,
            'cursor' => $this->cursor,
            'keyword' => $keyword,
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
        ];
    }
}
