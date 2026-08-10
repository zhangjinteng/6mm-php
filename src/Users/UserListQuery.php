<?php

declare(strict_types=1);

namespace SixMm\Shared\Users;

final class UserListQuery
{
    private const SORTABLE_FIELDS = [
        'public_user_id',
        'user_id',
        'wallet_balance',
        'volume_30d',
        'trade_volume_30d',
        'vip_level',
        'normal_pnl',
        'mimic_pnl',
        'total_realized_pnl',
        'created_at',
        'last_login_at',
    ];

    /** @var int[]|null */
    private ?array $includedPlatformUserIds;

    /** @var int[] */
    private array $excludedPlatformUserIds;

    /**
     * @param array<int, int|string>|null $includedPlatformUserIds
     * @param array<int, int|string> $excludedPlatformUserIds
     */
    public function __construct(
        private int $page = 1,
        private int $pageSize = 20,
        private string $keyword = '',
        private ?int $userType = null,
        private ?int $vipLevel = null,
        private ?int $onlineStatus = null,
        private ?string $createdAtStart = null,
        private ?string $createdAtEndExclusive = null,
        private string $orderBy = 'created_at',
        private string $orderDirection = 'desc',
        private ?string $volumeSince = null,
        ?array $includedPlatformUserIds = null,
        array $excludedPlatformUserIds = [],
        private ?int $agentId = null
    ) {
        $this->page = max(1, $this->page);
        $this->pageSize = min(1000, max(1, $this->pageSize));
        $this->keyword = trim($this->keyword);
        $this->orderBy = in_array($this->orderBy, self::SORTABLE_FIELDS, true)
            ? $this->orderBy
            : 'created_at';
        $this->orderDirection = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';
        $this->volumeSince = $this->volumeSince
            ?? (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $this->includedPlatformUserIds = $includedPlatformUserIds === null
            ? null
            : $this->normalizeIds($includedPlatformUserIds);
        $this->excludedPlatformUserIds = $this->normalizeIds($excludedPlatformUserIds);
        $this->agentId = $this->agentId !== null && $this->agentId >= 0
            ? $this->agentId
            : null;
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

    public function vipLevel(): ?int
    {
        return $this->vipLevel;
    }

    public function onlineStatus(): ?int
    {
        return $this->onlineStatus;
    }

    public function createdAtStart(): ?string
    {
        return $this->createdAtStart;
    }

    public function createdAtEndExclusive(): ?string
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

    public function volumeSince(): string
    {
        return (string) $this->volumeSince;
    }

    /** @return int[]|null */
    public function includedPlatformUserIds(): ?array
    {
        return $this->includedPlatformUserIds;
    }

    /** @return int[] */
    public function excludedPlatformUserIds(): array
    {
        return $this->excludedPlatformUserIds;
    }

    public function agentId(): ?int
    {
        return $this->agentId;
    }

    /** @param array<int, int|string> $ids
     *  @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        $normalized = array_map('intval', $ids);
        $normalized = array_filter($normalized, static fn (int $id): bool => $id > 0);

        return array_values(array_unique($normalized));
    }
}
