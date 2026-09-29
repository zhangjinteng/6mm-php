<?php

declare(strict_types=1);

namespace SixMm\Shared\UserContracts;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;
use SixMm\Shared\Users\UserListQuery;
use SixMm\Shared\Users\UserListQueryService;

/** Shared, read-only page assembly. TradeKernel snapshots remain host adapters. */
final class UserContractListService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * @param int[]|null $metricAgentIds Null is allowed for the platform's all-agent scope.
     * @param callable(int[]): array<int, string> $lastTradeAtMap
     * @return PageResult<array<string, mixed>>
     */
    public function search(
        UserListQuery $criteria,
        UserDataScope $scope,
        ?array $metricAgentIds,
        callable $lastTradeAtMap
    ): PageResult {
        $users = (new UserListQueryService($this->connection))->search($criteria, $scope);
        $rows = $users->items();
        if ($rows === []) {
            return new PageResult([], $users->total(), $users->page(), $users->pageSize());
        }

        $internalByPublicId = $this->connection->table('users')
            ->whereIn('public_user_id', array_column($rows, 'user_id'))
            ->pluck('user_id', 'public_user_id');
        $internalIds = array_values(array_unique(array_filter(array_map(
            'intval', $internalByPublicId->values()->all()
        ))));
        $metrics = (new UserContractMetricsService($this->connection))
            ->forUsers($internalIds, $metricAgentIds);
        $lastTrades = $lastTradeAtMap($internalIds);

        foreach ($rows as &$row) {
            $internalId = (int) ($internalByPublicId[(string) $row['user_id']] ?? 0);
            $row['internal_user_id'] = $internalId;
            $row['pnl_30d'] = $metrics[$internalId]['pnl_30d'] ?? '0';
            $row['fee_30d'] = $metrics[$internalId]['fee_30d'] ?? '0';
            $row['last_contract_at'] = $lastTrades[$internalId] ?? null;
        }
        unset($row);

        return new PageResult($rows, $users->total(), $users->page(), $users->pageSize());
    }
}
