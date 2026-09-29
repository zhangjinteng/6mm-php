<?php

declare(strict_types=1);

namespace SixMm\Shared\UserContracts;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/** Read-only contract report metrics keyed by the internal users.user_id. */
final class UserContractMetricsService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * @param array<int, int|string> $userIds
     * @param array<int, int|string>|null $agentIds Null means all agents (platform only).
     * @return array<int, array{fee_30d: string, pnl_30d: string}>
     */
    public function forUsers(array $userIds, ?array $agentIds, ?DateTimeImmutable $asOf = null): array
    {
        $userIds = $this->ids($userIds);
        $agentIds = $agentIds === null ? null : $this->ids($agentIds);
        if ($userIds === [] || $agentIds === []) {
            return [];
        }

        $feeMap = $this->handlingFee30dMap($userIds, $agentIds, $asOf);
        $pnlRows = $this->connection->table('user_daily_balance')
            ->whereIn('user_id', $userIds)
            ->select(['user_id', 'realized_pnl_cumulative', 'trade_date'])
            ->orderBy('user_id')
            ->orderByDesc('trade_date')
            ->get();
        $pnlMap = [];
        foreach ($pnlRows as $row) {
            $id = (int) $row->user_id;
            if (!isset($pnlMap[$id])) {
                $pnlMap[$id] = (string) ($row->realized_pnl_cumulative ?? '0');
            }
        }

        $result = [];
        foreach ($userIds as $id) {
            $result[$id] = [
                'fee_30d' => $feeMap[$id] ?? '0',
                'pnl_30d' => $pnlMap[$id] ?? '0',
            ];
        }
        return $result;
    }

    /** @param array<int, int|string> $userIds @param array<int, int|string>|null $agentIds @return array<int, string> */
    public function handlingFee30dMap(array $userIds, ?array $agentIds, ?DateTimeImmutable $asOf = null): array
    {
        $userIds = $this->ids($userIds);
        $agentIds = $agentIds === null ? null : $this->ids($agentIds);
        if ($userIds === [] || $agentIds === []) {
            return [];
        }

        $today = ($asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $start = $today->setTime(0, 0)->modify('-29 days')->format('Y-m-d');
        $end = $today->format('Y-m-d');

        $fees = $this->connection->table('admin_agent_futures_user_symbol_daily')
            ->whereIn('user_id', $userIds)
            ->whereBetween('stat_date', [$start, $end])
            ->whereRaw('UPPER(settle_asset) = ?', ['USDT']);
        if ($agentIds !== null) {
            $fees->whereIn('agent_id', $agentIds);
        }
        return $fees->groupBy('user_id')
            ->selectRaw('user_id, COALESCE(SUM(trading_fee), 0) AS fee_30d')
            ->pluck('fee_30d', 'user_id')
            ->mapWithKeys(static fn ($fee, $id): array => [(int) $id => (string) $fee])
            ->all();
    }

    /** @param array<int, int|string> $values @return int[] */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0
        )));
    }
}
