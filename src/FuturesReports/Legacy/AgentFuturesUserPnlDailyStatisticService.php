<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\Legacy\Models\Agent;
use SixMm\Shared\FuturesReports\Legacy\Models\AgentFuturesDailyStatistic;
use SixMm\Shared\FuturesReports\Legacy\Models\AgentFuturesUserPnlDailyStatistic;
use SixMm\Shared\FuturesReports\Legacy\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgentFuturesUserPnlDailyStatisticService
{
    public const CALCULATION_VERSION = 3;

    /**
     * 从完整小时事实重建模拟盘用户的已结算盈亏日报。
     *
     * @return int 写入日报行数
     */
    public function aggregate(?string $date = null, array $sourceRows = []): int
    {
        $rows = $this->buildRows($date, $sourceRows);
        $this->replaceRows($date, $rows);

        return count($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $sourceRows
     * @return array<int, array<string, mixed>>
     */
    public function buildRows(
        ?string $date,
        array $sourceRows,
        ?array $agentIds = null,
        ?array $allTradeRows = null
    ): array
    {
        $statDate = $this->parseDate($date);
        $agentIds = $agentIds ?? $this->mainAgentIds();
        $agentIds = array_values(array_unique(array_filter(
            array_map('intval', $agentIds),
            static fn (int $agentId): bool => $agentId > 0
        )));

        if (empty($agentIds)) {
            return [];
        }

        $users = [];

        foreach ($sourceRows as $sourceRow) {
            // 兼容尚未重建的旧小时桶，禁止其中的实盘数据进入模拟盈亏日报。
            if ((int) ($sourceRow['user_type'] ?? 0) === User::USER_TYPE_NORMAL) {
                continue;
            }

            $user = &$this->userMetric($users, $sourceRow);
            $closeOrderIds = $this->ids($sourceRow['close_order_ids'] ?? []);
            if ($closeOrderIds !== []) {
                foreach ($closeOrderIds as $closeOrderId) {
                    $user['close_order_ids'][$closeOrderId] = true;
                }
                $user['close_order_count'] = count($user['close_order_ids']);
            } else {
                $user['close_order_count'] += max(
                    0,
                    (int) ($sourceRow['close_order_count'] ?? 0)
                );
            }
            foreach ([
                'close_amount',
                'realized_pnl',
                'trading_fee',
                'funding_fee',
                'liquidation_fee',
            ] as $field) {
                $user[$field] = $this->add(
                    $user[$field],
                    $sourceRow[$field] ?? '0'
                );
            }
            unset($user);
        }

        $allTradingFees = $allTradeRows === null
            ? null
            : $this->allTradingFees($allTradeRows);
        if ($allTradingFees !== null) {
            foreach ($users as $agentId => &$usersByType) {
                foreach ($usersByType as $userType => &$usersById) {
                    foreach ($usersById as $userId => &$user) {
                        $user['trading_fee'] = $allTradingFees['by_user']
                            [$agentId][$userType][$userId] ?? '0';
                    }
                    unset($user);
                }
                unset($usersById);
            }
            unset($usersByType);
        }

        $now = CarbonImmutable::now('UTC');
        $rows = [];
        foreach ($agentIds as $agentId) {
            $rows[] = $this->summaryRow(
                $agentId,
                $statDate,
                User::USER_TYPE_SIMULATE,
                $users[$agentId][User::USER_TYPE_SIMULATE] ?? [],
                $now,
                $allTradingFees['by_agent'][$agentId][User::USER_TYPE_SIMULATE]
                    ?? ($allTradingFees === null ? null : '0')
            );
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function replaceRows(?string $date, array $rows): void
    {
        $statDate = $this->parseDate($date);
        $agentIds = $this->mainAgentIds();

        DB::transaction(static function () use ($agentIds, $statDate, $rows): void {
            AgentFuturesUserPnlDailyStatistic::query()
                ->whereIn('agent_id', $agentIds)
                ->where('stat_date', $statDate->toDateString())
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                AgentFuturesUserPnlDailyStatistic::query()->insert($chunk);
            }
        });
    }

    /**
     * @param array<int, array<int, array<int, array<string, mixed>>>> $users
     * @param array<string, mixed> $sourceRow
     *
     * @return array<string, mixed>
     */
    private function &userMetric(array &$users, array $sourceRow): array
    {
        $agentId = (int) ($sourceRow['agent_id'] ?? 0);
        $userType = (int) ($sourceRow['user_type'] ?? 0);
        $userId = (int) ($sourceRow['user_id'] ?? 0);

        if (
            $agentId <= 0 ||
            $userId <= 0 ||
            $userType !== User::USER_TYPE_SIMULATE
        ) {
            throw new InvalidArgumentException('用户盈亏日报来源数据身份无效');
        }

        if (!isset($users[$agentId][$userType][$userId])) {
            $users[$agentId][$userType][$userId] = [
                'close_order_ids' => [],
                'close_order_count' => 0,
                'close_amount' => '0',
                'realized_pnl' => '0',
                'trading_fee' => '0',
                'funding_fee' => '0',
                'liquidation_fee' => '0',
            ];
        }

        return $users[$agentId][$userType][$userId];
    }

    /** @return string[] */
    private function ids($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            $id = trim((string) $id);
            if ($id !== '' && $id !== '0') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int, array<string, mixed>> $users
     *
     * @return array<string, mixed>
     */
    private function summaryRow(
        int $agentId,
        CarbonImmutable $statDate,
        int $userType,
        array $users,
        CarbonImmutable $now,
        ?string $allTradingFee = null
    ): array {
        $summary = [
            'settled_user_count' => count($users),
            'profitable_user_count' => 0,
            'losing_user_count' => 0,
            'breakeven_user_count' => 0,
            'close_order_count' => 0,
            'close_amount' => '0',
            'realized_pnl' => '0',
            'trading_fee' => $allTradingFee ?? '0',
            'funding_fee' => '0',
            'liquidation_fee' => '0',
            'true_net_pnl' => '0',
        ];

        foreach ($users as $user) {
            $summary['close_order_count'] += max(
                0,
                (int) ($user['close_order_count'] ?? 0)
            );
            foreach ([
                'close_amount',
                'realized_pnl',
                'funding_fee',
                'liquidation_fee',
            ] as $field) {
                $summary[$field] = $this->add(
                    $summary[$field],
                    $user[$field] ?? '0'
                );
            }
            if ($allTradingFee === null) {
                $summary['trading_fee'] = $this->add(
                    $summary['trading_fee'],
                    $user['trading_fee'] ?? '0'
                );
            }

            $trueNetPnl = $this->trueNetPnl($user);
            $comparison = bccomp($trueNetPnl, '0', 16);
            if ($comparison > 0) {
                $summary['profitable_user_count']++;
            } elseif ($comparison < 0) {
                $summary['losing_user_count']++;
            } else {
                $summary['breakeven_user_count']++;
            }
        }

        $summary['true_net_pnl'] = $this->trueNetPnl($summary);

        $row = [
            'agent_id' => $agentId,
            'stat_date' => $statDate->toDateString(),
            'user_type' => $userType,
            'settle_asset' => FuturesDailySourceProvider::SETTLE_ASSET,
            'settled_user_count' => $summary['settled_user_count'],
            'profitable_user_count' => $summary['profitable_user_count'],
            'losing_user_count' => $summary['losing_user_count'],
            'breakeven_user_count' => $summary['breakeven_user_count'],
            'close_order_count' => $summary['close_order_count'],
            'close_amount' => $summary['close_amount'],
            'realized_pnl' => $summary['realized_pnl'],
            'trading_fee' => $summary['trading_fee'],
            'funding_fee' => $summary['funding_fee'],
            'liquidation_fee' => $summary['liquidation_fee'],
            'status' => AgentFuturesDailyStatistic::STATUS_PROCESSING,
            'calculation_version' => self::CALCULATION_VERSION,
            'last_aggregated_at' => $now,
            'finalized_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // PostgreSQL 使用生成列；SQLite/MySQL 测试表使用普通字段。
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $row['true_net_pnl'] = $summary['true_net_pnl'];
        }

        return $row;
    }

    /**
     * 交易手续费按当天全部成交统计；用户结算分类仍只使用结算成交形成的用户集合。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{by_user: array, by_agent: array}
     */
    private function allTradingFees(array $rows): array
    {
        $fees = [
            'by_user' => [],
            'by_agent' => [],
        ];

        foreach ($rows as $row) {
            $agentId = (int) ($row['agent_id'] ?? 0);
            $userType = (int) ($row['user_type'] ?? 0);
            $userId = (int) ($row['user_id'] ?? 0);
            $settleAsset = strtoupper((string) ($row['settle_asset'] ?? ''));
            if (
                $agentId <= 0 ||
                $userId <= 0 ||
                $userType !== User::USER_TYPE_SIMULATE ||
                $settleAsset !== FuturesDailySourceProvider::SETTLE_ASSET
            ) {
                continue;
            }

            $fee = $row['trading_fee'] ?? '0';
            $fees['by_user'][$agentId][$userType][$userId] = $this->add(
                $fees['by_user'][$agentId][$userType][$userId] ?? '0',
                $fee
            );
            $fees['by_agent'][$agentId][$userType] = $this->add(
                $fees['by_agent'][$agentId][$userType] ?? '0',
                $fee
            );
        }

        return $fees;
    }

    /**
     * 费用字段采用“正数支出”的保存方式；资金费为负数时表示用户收取，
     * 因而减去负数会自然计入收入。
     *
     * @param array<string, mixed> $user
     */
    private function trueNetPnl(array $user): string
    {
        $value = $this->subtract(
            $user['realized_pnl'] ?? '0',
            $user['trading_fee'] ?? '0'
        );
        $value = $this->subtract($value, $user['funding_fee'] ?? '0');

        return $this->subtract($value, $user['liquidation_fee'] ?? '0');
    }

    private function parseDate(?string $date): CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return CarbonImmutable::now('UTC')->startOfDay();
        }

        $date = trim($date);

        try {
            $parsed = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $date . ' 00:00:00',
                'UTC'
            );
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('统计日期格式必须为 YYYY-MM-DD', 0, $e);
        }

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('统计日期格式必须为 YYYY-MM-DD');
        }

        return $parsed->startOfDay();
    }

    /** @return int[] */
    private function mainAgentIds(): array
    {
        return Agent::query()
            ->where('pid', 0)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($agentId): int => (int) $agentId)
            ->all();
    }

    private function add($left, $right): string
    {
        return bcadd($this->decimal($left), $this->decimal($right), 16);
    }

    private function subtract($left, $right): string
    {
        return bcsub($this->decimal($left), $this->decimal($right), 16);
    }

    private function absolute($value): string
    {
        $decimal = $this->decimal($value);

        return bccomp($decimal, '0', 16) < 0
            ? bcsub('0', $decimal, 16)
            : $decimal;
    }

    private function decimal($value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '0';
        }

        return (string) $value;
    }
}
