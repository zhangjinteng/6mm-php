<?php

declare(strict_types=1);

namespace SixMm\Shared\MarginChangeLogs;

final class MarginChangeLogListQuery
{
    private const SORTABLE_FIELDS = [
        'created_at',
        'biz_type',
        'delta_amount',
        'balance_before',
        'balance_after',
    ];

    private const EQUIVALENT_BIZ_TYPE_GROUPS = [
        ['fee', 'handling_fee'],
        ['funding_fee', 'funding_fee_settle'],
        ['fee_commission', 'commission_rebate'],
    ];

    /** @var string[] */
    private array $bizTypes;

    /**
     * @param array<int, string> $bizTypes
     */
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        array $bizTypes = [],
        private bool $includeZeroAmount = false,
        private string $userId = '',
        private string $username = '',
        private ?int $userType = null,
        private string $createdAtStart = '',
        private string $createdAtEndExclusive = '',
        private string $orderBy = 'created_at',
        private string $orderDirection = 'desc'
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(100, max(1, $this->pageSize));
        $this->bizTypes = $this->expandEquivalentBizTypes($bizTypes);
        $this->userId = trim($this->userId);
        $this->username = trim($this->username);
        $this->userType = $this->userType !== null && in_array($this->userType, [1, 2, 3], true)
            ? $this->userType
            : null;
        $this->createdAtStart = trim($this->createdAtStart);
        $this->createdAtEndExclusive = trim($this->createdAtEndExclusive);
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

    /** @return string[] */
    public function bizTypes(): array
    {
        return $this->bizTypes;
    }

    public function includeZeroAmount(): bool
    {
        return $this->includeZeroAmount;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function userType(): ?int
    {
        return $this->userType;
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

    /**
     * @param array<int, string> $bizTypes
     * @return string[]
     */
    private function expandEquivalentBizTypes(array $bizTypes): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $bizTypes
        ))));
        $expanded = [];

        foreach ($normalized as $bizType) {
            $equivalentGroup = null;
            foreach (self::EQUIVALENT_BIZ_TYPE_GROUPS as $group) {
                if (in_array($bizType, $group, true)) {
                    $equivalentGroup = $group;
                    break;
                }
            }
            array_push($expanded, ...($equivalentGroup ?? [$bizType]));
        }

        return array_values(array_unique($expanded));
    }
}
