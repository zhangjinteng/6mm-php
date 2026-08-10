<?php

declare(strict_types=1);

namespace SixMm\Shared\ConditionOrders;

final class ConditionOrderListQuery
{
    private const USER_TYPES = [1, 2, 3];
    private const SIDES = ['buy', 'sell'];

    private ?int $userType;
    private ?string $orderType;
    private ?string $side;
    private ?bool $reduceOnly;

    public function __construct(
        private ConditionOrderKind $kind,
        private ConditionOrderLifecycle $lifecycle,
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        ?int $userType = null,
        private string $symbol = '',
        ?string $orderType = null,
        ?string $side = null,
        bool|int|string|null $reduceOnly = null,
        private ?string $createdAtStart = null,
        private ?string $createdAtEndExclusive = null
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->userType = in_array($userType, self::USER_TYPES, true) ? $userType : null;
        $this->symbol = strtoupper(trim($this->symbol));
        if ($this->symbol === 'ALL') {
            $this->symbol = '';
        }

        $normalizedOrderType = strtolower(trim((string) $orderType));
        $this->orderType = $normalizedOrderType !== '' && $normalizedOrderType !== 'all'
            ? $normalizedOrderType
            : null;

        $normalizedSide = strtolower(trim((string) $side));
        $this->side = in_array($normalizedSide, self::SIDES, true) ? $normalizedSide : null;
        $this->reduceOnly = $this->normalizeBoolean($reduceOnly);
        $this->createdAtStart = $this->normalizeOptionalString($this->createdAtStart);
        $this->createdAtEndExclusive = $this->normalizeOptionalString($this->createdAtEndExclusive);
    }

    public function kind(): ConditionOrderKind
    {
        return $this->kind;
    }

    public function lifecycle(): ConditionOrderLifecycle
    {
        return $this->lifecycle;
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

    public function orderType(): ?string
    {
        return $this->orderType;
    }

    public function side(): ?string
    {
        return $this->side;
    }

    public function reduceOnly(): ?bool
    {
        return $this->reduceOnly;
    }

    public function createdAtStart(): ?string
    {
        return $this->createdAtStart;
    }

    public function createdAtEndExclusive(): ?string
    {
        return $this->createdAtEndExclusive;
    }

    private function normalizeBoolean(bool|int|string|null $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || strtolower(trim((string) $value)) === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || strtolower(trim((string) $value)) === 'false') {
            return false;
        }

        return null;
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
