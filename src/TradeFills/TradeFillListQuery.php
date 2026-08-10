<?php

declare(strict_types=1);

namespace SixMm\Shared\TradeFills;

final class TradeFillListQuery
{
    private const SORTABLE_FIELDS = [
        'trade_time',
        'order_id',
        'position_id',
        'symbol',
        'side',
        'quantity',
        'price',
        'trade_value',
        'handling_fee',
        'role_type',
        'realized_pnl',
    ];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $positionId = null,
        private string $placeType = '',
        private ?int $userType = null,
        private string $symbol = '',
        private ?int $marginMode = null,
        private string $side = '',
        private string $roleType = '',
        private string $tradeTimeStart = '',
        private string $tradeTimeEnd = '',
        private string $orderBy = 'trade_time',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->positionId = $this->positionId !== null && $this->positionId > 0
            ? $this->positionId
            : null;
        $placeType = strtoupper(trim($this->placeType));
        $this->placeType = $placeType === 'LIQUIDATION' ? $placeType : '';
        $this->userType = in_array($this->userType, [1, 2, 3], true)
            ? $this->userType
            : null;
        $this->symbol = strtoupper(trim($this->symbol));
        $this->marginMode = in_array($this->marginMode, [1, 2], true)
            ? $this->marginMode
            : null;
        $side = strtolower(trim($this->side));
        $this->side = in_array($side, ['buy', 'sell'], true) ? $side : '';
        $roleType = strtolower(trim($this->roleType));
        $this->roleType = in_array($roleType, ['maker', 'taker'], true) ? $roleType : '';
        $this->tradeTimeStart = trim($this->tradeTimeStart);
        $this->tradeTimeEnd = trim($this->tradeTimeEnd);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'trade_time';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
    }

    public function page(): int { return $this->page; }
    public function pageSize(): int { return $this->pageSize; }
    public function keyword(): string { return $this->keyword; }
    public function positionId(): ?int { return $this->positionId; }
    public function placeType(): string { return $this->placeType; }
    public function userType(): ?int { return $this->userType; }
    public function symbol(): string { return $this->symbol; }
    public function marginMode(): ?int { return $this->marginMode; }
    public function side(): string { return $this->side; }
    public function roleType(): string { return $this->roleType; }
    public function tradeTimeStart(): string { return $this->tradeTimeStart; }
    public function tradeTimeEnd(): string { return $this->tradeTimeEnd; }
    public function orderBy(): string { return $this->orderBy; }
    public function orderDirection(): string { return $this->orderDirection; }
}
