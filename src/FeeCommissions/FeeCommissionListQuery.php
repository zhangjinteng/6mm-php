<?php

declare(strict_types=1);

namespace SixMm\Shared\FeeCommissions;

final class FeeCommissionListQuery
{
    private const SORTABLE_FIELDS = [
        'trade_time',
        'public_user_id',
        'user_id',
        'order_id',
        'position_id',
        'trade_value',
        'handling_fee',
        'commission_amount',
    ];

    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private string $symbol = '',
        private string|int|null $marginMode = null,
        private string $side = '',
        private string $roleType = '',
        private string $tradeTimeStart = '',
        private string $tradeTimeEndExclusive = '',
        private string $orderBy = 'trade_time',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->symbol = trim($this->symbol);
        $this->marginMode = $this->normalizeMarginMode($this->marginMode);
        $this->side = $this->normalizeChoice($this->side, ['buy', 'sell']);
        $this->roleType = $this->normalizeChoice($this->roleType, ['maker', 'taker']);
        $this->tradeTimeStart = trim($this->tradeTimeStart);
        $this->tradeTimeEndExclusive = trim($this->tradeTimeEndExclusive);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'trade_time';
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

    public function symbol(): string
    {
        return $this->symbol;
    }

    public function marginMode(): ?string
    {
        return $this->marginMode;
    }

    public function side(): string
    {
        return $this->side;
    }

    public function roleType(): string
    {
        return $this->roleType;
    }

    public function tradeTimeStart(): string
    {
        return $this->tradeTimeStart;
    }

    public function tradeTimeEndExclusive(): string
    {
        return $this->tradeTimeEndExclusive;
    }

    public function orderBy(): string
    {
        return $this->orderBy;
    }

    public function orderDirection(): string
    {
        return $this->orderDirection;
    }

    /** @return array<string, string|null> */
    public function cacheKeyParts(): array
    {
        return [
            'keyword' => $this->keyword,
            'symbol' => $this->symbol,
            'margin_mode' => $this->marginMode,
            'side' => $this->side,
            'role_type' => $this->roleType,
            'trade_time_start' => $this->tradeTimeStart,
            'trade_time_end_exclusive' => $this->tradeTimeEndExclusive,
        ];
    }

    private function normalizeMarginMode(string|int|null $value): ?string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            '1', 'cross' => 'cross',
            '2', 'isolated' => 'isolated',
            default => null,
        };
    }

    /** @param string[] $allowed */
    private function normalizeChoice(string $value, array $allowed): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, $allowed, true) ? $normalized : '';
    }
}
