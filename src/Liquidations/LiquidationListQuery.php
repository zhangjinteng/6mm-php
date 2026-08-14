<?php

declare(strict_types=1);

namespace SixMm\Shared\Liquidations;

final class LiquidationListQuery
{
    private const SORTABLE_FIELDS = [
        'user_id',
        'user_type',
        'position_id',
        'symbol',
        'position_side',
        'margin_mode',
        'leverage',
        'liquidation_quantity',
        'average_execution_price',
        'liquidation_fee',
        'occurred_at',
    ];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $userType = null,
        private string $productCategory = '',
        private string $symbol = '',
        private string $positionSide = '',
        private string $occurredAtStart = '',
        private string $occurredAtEnd = '',
        private string $orderBy = 'occurred_at',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->userType = in_array($this->userType, [1, 2, 3], true) ? $this->userType : null;
        $this->productCategory = strtolower(trim($this->productCategory));
        $this->symbol = strtoupper(trim($this->symbol));
        $side = strtolower(trim($this->positionSide));
        $this->positionSide = in_array($side, ['long', 'short'], true) ? $side : '';
        $this->occurredAtStart = trim($this->occurredAtStart);
        $this->occurredAtEnd = trim($this->occurredAtEnd);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'occurred_at';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
    }

    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function keyword(): string { return $this->keyword; }
    public function userType(): ?int { return $this->userType; }
    public function productCategory(): string { return $this->productCategory; }
    public function symbol(): string { return $this->symbol; }
    public function positionSide(): string { return $this->positionSide; }
    public function occurredAtStart(): string { return $this->occurredAtStart; }
    public function occurredAtEnd(): string { return $this->occurredAtEnd; }
    public function orderBy(): string { return $this->orderBy; }
    public function orderDirection(): string { return $this->orderDirection; }
}
