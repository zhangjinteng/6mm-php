<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\Legacy\Models\Agent;
use SixMm\Shared\FuturesReports\Legacy\Models\AgentFuturesDailyStatistic;
use SixMm\Shared\FuturesReports\Legacy\Models\AgentFuturesUserSymbolDaily;
use SixMm\Shared\FuturesReports\Legacy\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgentFuturesDailyStatisticService
{
    private const CALCULATION_VERSION = 7;

    /**
     * 从用户-合约日事实和小时成交口径生成页面直接查询的日报汇总。
     *
     * @return int 写入汇总行数
     */
    public function aggregate(?string $date = null, array $executionRows = []): int
    {
        $rows = $this->buildRows($date, $executionRows);
        $this->replaceRows($date, $rows);

        return count($rows);
    }

    /**
     * 只构造待写入行，供协调器与用户盈亏日报在同一事务中原子替换。
     *
     * @param array<int, array<string, mixed>> $executionRows
     * @return array<int, array<string, mixed>>
     */
    public function buildRows(
        ?string $date,
        array $executionRows,
        ?array $agentIds = null
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

        $facts = AgentFuturesUserSymbolDaily::query()
            ->whereIn('agent_id', $agentIds)
            ->where('stat_date', $statDate->toDateString())
            ->get()
            ->map(static fn (AgentFuturesUserSymbolDaily $row): array => $row->toArray())
            ->all();
        $executionMetrics = $this->executionMetrics($executionRows);

        $buckets = [];
        foreach ($agentIds as $agentId) {
            $buckets[$agentId][FuturesDailySourceProvider::SETTLE_ASSET] = [];
        }
        foreach ($facts as $fact) {
            $agentId = (int) ($fact['agent_id'] ?? 0);
            $settleAsset = strtoupper(trim((string) (
                $fact['settle_asset'] ?? FuturesDailySourceProvider::SETTLE_ASSET
            )));
            if (isset($buckets[$agentId])) {
                $buckets[$agentId][$settleAsset][] = $fact;
            }
        }

        $now = CarbonImmutable::now('UTC');
        $rows = [];
        foreach ($buckets as $agentId => $assetBuckets) {
            foreach ($assetBuckets as $settleAsset => $assetFacts) {
                foreach (
                    [
                        AgentFuturesDailyStatistic::USER_TYPE_ALL,
                        User::USER_TYPE_NORMAL,
                        User::USER_TYPE_SIMULATE,
                    ] as $userType
                ) {
                    $typeFacts = array_values(array_filter(
                        $assetFacts,
                        static fn (array $fact): bool =>
                            $userType === AgentFuturesDailyStatistic::USER_TYPE_ALL ||
                            (int) ($fact['user_type'] ?? 0) === $userType
                    ));

                    $rows[] = $this->summaryRow(
                        (int) $agentId,
                        $statDate,
                        $userType,
                        (string) $settleAsset,
                        AgentFuturesDailyStatistic::SYMBOL_SCOPE_ALL,
                        '',
                        $typeFacts,
                        $now,
                        $executionMetrics[$this->executionMetricKey(
                            (int) $agentId,
                            $userType,
                            (string) $settleAsset,
                            AgentFuturesDailyStatistic::SYMBOL_SCOPE_ALL,
                            ''
                        )] ?? null
                    );

                    $symbols = [];
                    foreach ($typeFacts as $fact) {
                        $symbol = strtoupper(trim((string) ($fact['symbol'] ?? '')));
                        if (
                            $symbol !== '' &&
                            $symbol !== FuturesDailySourceProvider::UNASSIGNED_SYMBOL
                        ) {
                            $symbols[$symbol] = $symbol;
                        }
                    }
                    ksort($symbols);

                    foreach ($symbols as $symbol) {
                        $symbolFacts = array_values(array_filter(
                            $typeFacts,
                            static fn (array $fact): bool =>
                                strtoupper((string) ($fact['symbol'] ?? '')) === $symbol
                        ));
                        $rows[] = $this->summaryRow(
                            (int) $agentId,
                            $statDate,
                            $userType,
                            (string) $settleAsset,
                            AgentFuturesDailyStatistic::SYMBOL_SCOPE_SINGLE,
                            $symbol,
                            $symbolFacts,
                            $now,
                            $executionMetrics[$this->executionMetricKey(
                                (int) $agentId,
                                $userType,
                                (string) $settleAsset,
                                AgentFuturesDailyStatistic::SYMBOL_SCOPE_SINGLE,
                                $symbol
                            )] ?? null
                        );
                    }
                }
            }
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function replaceRows(?string $date, array $rows): void
    {
        $statDate = $this->parseDate($date);
        $agentIds = $this->mainAgentIds();

        DB::transaction(static function () use ($agentIds, $statDate, $rows): void {
            AgentFuturesDailyStatistic::query()
                ->whereIn('agent_id', $agentIds)
                ->where('stat_date', $statDate->toDateString())
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                AgentFuturesDailyStatistic::query()->insert($chunk);
            }
        });
    }

    /**
     * @return array<string, int>
     */
    public function aggregateRecent(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $dates = [
            $today->subDay()->toDateString(),
            $today->toDateString(),
        ];
        $result = [];

        foreach ($dates as $date) {
            $result[$date] = $this->aggregate($date);
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     *
     * @return array<string, mixed>
     */
    private function summaryRow(
        int $agentId,
        CarbonImmutable $statDate,
        int $userType,
        string $settleAsset,
        int $symbolScope,
        string $symbol,
        array $facts,
        CarbonImmutable $now,
        ?array $executionMetric = null
    ): array {
        $summary = [
            'trading_user_count' => 0,
            'order_count' => 0,
            'order_amount' => '0',
            'filled_order_count' => 0,
            'fill_count' => 0,
            'trade_amount' => '0',
            'cancel_order_count' => 0,
            'cancel_amount' => '0',
            'trading_fee' => '0',
            'commission_user_count' => 0,
            'commission_count' => 0,
            'maker_commission' => '0',
            'taker_commission' => '0',
            'fee_user_count' => 0,
            'fee_count' => 0,
            'maker_trade_amount' => '0',
            'taker_trade_amount' => '0',
            'maker_handling_fee' => '0',
            'taker_handling_fee' => '0',
            'position_user_count' => 0,
            'long_position_user_count' => 0,
            'short_position_user_count' => 0,
            'closing_position_amount' => '0',
            'long_position_amount' => '0',
            'short_position_amount' => '0',
            'occupied_margin' => '0',
            'unrealized_pnl' => '0',
            'realized_pnl' => '0',
            'funding_fee' => '0',
            'funding_settlement_user_count' => 0,
            'funding_settlement_count' => 0,
            'funding_settlement_position_amount' => '0',
            'funding_fee_income' => '0',
            'funding_fee_expense' => '0',
            'liquidation_count' => 0,
            'liquidation_fee' => '0',
            'liquidation_user_count' => 0,
            'liquidation_position_amount' => '0',
            'liquidation_trade_amount' => '0',
            'liquidation_realized_pnl' => '0',
            'bankruptcy_count' => 0,
            'bankruptcy_loss' => '0',
            'uncollected_liquidation_fee' => '0',
            'uncollected_trading_fee' => '0',
            'margin_change' => '0',
            'profitable_user_count' => 0,
            'losing_user_count' => 0,
            'profit_amount' => '0',
            'loss_amount' => '0',
            'net_pnl' => '0',
        ];
        $tradingUsers = [];
        $commissionUsers = [];
        $feeUsers = [];
        $positionUsers = [];
        $longPositionUsers = [];
        $shortPositionUsers = [];
        $fundingSettlementUsers = [];
        $liquidationUsers = [];
        $pnlByUser = [];

        foreach ($facts as $fact) {
            $userId = (int) ($fact['user_id'] ?? 0);
            $fillCount = max(0, (int) ($fact['fill_count'] ?? 0));
            $commissionCount = max(0, (int) ($fact['commission_count'] ?? 0));
            $feeCount = max(0, (int) ($fact['fee_count'] ?? 0));
            $positionAmount = $this->decimal($fact['closing_position_amount'] ?? '0');
            $longPositionAmount = $this->decimal(
                $fact['long_position_amount'] ?? '0'
            );
            $shortPositionAmount = $this->decimal(
                $fact['short_position_amount'] ?? '0'
            );
            $liquidationCount = max(0, (int) ($fact['liquidation_count'] ?? 0));
            $fundingSettlementCount = max(
                0,
                (int) ($fact['funding_settlement_count'] ?? 0)
            );
            $realizedPnl = $this->decimal($fact['realized_pnl'] ?? '0');

            if ($fillCount > 0 && $userId > 0) {
                $tradingUsers[$userId] = true;
            }
            if ($commissionCount > 0 && $userId > 0) {
                $commissionUsers[$userId] = true;
            }
            if ($feeCount > 0 && $userId > 0) {
                $feeUsers[$userId] = true;
            }
            if (bccomp($this->absolute($positionAmount), '0', 16) > 0 && $userId > 0) {
                $positionUsers[$userId] = true;
            }
            if (bccomp($longPositionAmount, '0', 16) > 0 && $userId > 0) {
                $longPositionUsers[$userId] = true;
            }
            if (bccomp($shortPositionAmount, '0', 16) > 0 && $userId > 0) {
                $shortPositionUsers[$userId] = true;
            }
            if ($liquidationCount > 0 && $userId > 0) {
                $liquidationUsers[$userId] = true;
            }
            if ($fundingSettlementCount > 0 && $userId > 0) {
                $fundingSettlementUsers[$userId] = true;
            }
            if ($userId > 0) {
                $pnlByUser[$userId] = $this->add(
                    $pnlByUser[$userId] ?? '0',
                    $realizedPnl
                );
            }

            $summary['order_count'] += max(0, (int) ($fact['order_count'] ?? 0));
            $summary['order_amount'] = $this->add(
                $summary['order_amount'],
                $fact['order_amount'] ?? '0'
            );
            $summary['filled_order_count'] += max(
                0,
                (int) ($fact['filled_order_count'] ?? 0)
            );
            $summary['fill_count'] += $fillCount;
            $summary['trade_amount'] = $this->add(
                $summary['trade_amount'],
                $fact['trade_amount'] ?? '0'
            );
            $summary['trading_fee'] = $this->add(
                $summary['trading_fee'],
                $fact['trading_fee'] ?? '0'
            );
            $summary['cancel_order_count'] += max(
                0,
                (int) ($fact['cancel_order_count'] ?? 0)
            );
            $summary['cancel_amount'] = $this->add(
                $summary['cancel_amount'],
                $fact['cancel_amount'] ?? '0'
            );
            $summary['commission_count'] += $commissionCount;
            $summary['fee_count'] += $feeCount;
            $summary['maker_commission'] = $this->add(
                $summary['maker_commission'],
                $fact['maker_commission'] ?? '0'
            );
            $summary['taker_commission'] = $this->add(
                $summary['taker_commission'],
                $fact['taker_commission'] ?? '0'
            );
            foreach ([
                'maker_trade_amount',
                'taker_trade_amount',
                'maker_handling_fee',
                'taker_handling_fee',
            ] as $field) {
                $summary[$field] = $this->add(
                    $summary[$field],
                    $fact[$field] ?? '0'
                );
            }
            $summary['closing_position_amount'] = $this->add(
                $summary['closing_position_amount'],
                $positionAmount
            );
            foreach ([
                'long_position_amount',
                'short_position_amount',
                'occupied_margin',
                'unrealized_pnl',
            ] as $field) {
                $summary[$field] = $this->add(
                    $summary[$field],
                    $fact[$field] ?? '0'
                );
            }
            $summary['realized_pnl'] = $this->add(
                $summary['realized_pnl'],
                $realizedPnl
            );
            $summary['funding_fee'] = $this->add(
                $summary['funding_fee'],
                $fact['funding_fee'] ?? '0'
            );
            $summary['funding_settlement_count'] += $fundingSettlementCount;
            foreach ([
                'funding_settlement_position_amount',
                'funding_fee_income',
                'funding_fee_expense',
            ] as $field) {
                $summary[$field] = $this->add(
                    $summary[$field],
                    $fact[$field] ?? '0'
                );
            }
            $summary['liquidation_count'] += $liquidationCount;
            $summary['bankruptcy_count'] += max(
                0,
                (int) ($fact['bankruptcy_count'] ?? 0)
            );
            $summary['liquidation_fee'] = $this->add(
                $summary['liquidation_fee'],
                $fact['liquidation_fee'] ?? '0'
            );
            foreach ([
                'liquidation_position_amount',
                'liquidation_trade_amount',
                'liquidation_realized_pnl',
                'bankruptcy_loss',
                'uncollected_liquidation_fee',
                'uncollected_trading_fee',
            ] as $field) {
                $summary[$field] = $this->add(
                    $summary[$field],
                    $fact[$field] ?? '0'
                );
            }
            $summary['margin_change'] = $this->add(
                $summary['margin_change'],
                $fact['margin_change'] ?? '0'
            );
        }

        foreach ($pnlByUser as $userPnl) {
            $comparison = bccomp($userPnl, '0', 16);
            if ($comparison > 0) {
                $summary['profitable_user_count']++;
                $summary['profit_amount'] = $this->add(
                    $summary['profit_amount'],
                    $userPnl
                );
            } elseif ($comparison < 0) {
                $summary['losing_user_count']++;
                $summary['loss_amount'] = $this->add(
                    $summary['loss_amount'],
                    $this->absolute($userPnl)
                );
            }
        }

        $summary['trading_user_count'] = count($tradingUsers);
        $summary['commission_user_count'] = count($commissionUsers);
        $summary['fee_user_count'] = count($feeUsers);
        $summary['position_user_count'] = count($positionUsers);
        $summary['long_position_user_count'] = count($longPositionUsers);
        $summary['short_position_user_count'] = count($shortPositionUsers);
        $summary['funding_settlement_user_count'] = count(
            $fundingSettlementUsers
        );
        $summary['liquidation_user_count'] = count($liquidationUsers);
        $summary['net_pnl'] = $this->subtract(
            $summary['profit_amount'],
            $summary['loss_amount']
        );
        if ($executionMetric !== null) {
            $summary['fill_count'] = max(
                0,
                (int) ($executionMetric['fill_count'] ?? 0)
            );
            $summary['trade_amount'] = $this->decimal(
                $executionMetric['trade_amount'] ?? '0'
            );
        }

        return array_merge([
            'agent_id' => $agentId,
            'stat_date' => $statDate->toDateString(),
            'user_type' => $userType,
            'settle_asset' => $settleAsset,
            'symbol_scope' => $symbolScope,
            'symbol' => $symbol,
        ], $summary, [
            'status' => AgentFuturesDailyStatistic::STATUS_PROCESSING,
            'calculation_version' => self::CALCULATION_VERSION,
            'last_aggregated_at' => $now,
            'finalized_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 将撮合去重后的合约指标汇总为全部和单合约两个范围。
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, array{fill_count: int, trade_amount: string}>
     */
    private function executionMetrics(array $rows): array
    {
        $metrics = [];

        foreach ($rows as $row) {
            $agentId = (int) ($row['agent_id'] ?? 0);
            $userType = (int) ($row['user_type'] ?? -1);
            $settleAsset = strtoupper(trim((string) (
                $row['settle_asset'] ?? FuturesDailySourceProvider::SETTLE_ASSET
            )));
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            $fillCount = max(0, (int) ($row['fill_count'] ?? 0));
            $tradeAmount = $this->decimal($row['trade_amount'] ?? '0');
            $tradeIds = $this->ids($row['trade_ids'] ?? []);

            if (
                $agentId <= 0 ||
                !in_array($userType, [
                    AgentFuturesDailyStatistic::USER_TYPE_ALL,
                    User::USER_TYPE_NORMAL,
                    User::USER_TYPE_SIMULATE,
                ], true) ||
                $settleAsset === '' ||
                $symbol === ''
            ) {
                continue;
            }

            foreach ([
                [AgentFuturesDailyStatistic::SYMBOL_SCOPE_ALL, ''],
                [AgentFuturesDailyStatistic::SYMBOL_SCOPE_SINGLE, $symbol],
            ] as [$scope, $scopeSymbol]) {
                $key = $this->executionMetricKey(
                    $agentId,
                    $userType,
                    $settleAsset,
                    $scope,
                    $scopeSymbol
                );
                if (!isset($metrics[$key])) {
                    $metrics[$key] = [
                        'fill_count' => 0,
                        'trade_amount' => '0',
                        'trade_ids' => [],
                    ];
                }
                if ($tradeIds !== []) {
                    foreach ($tradeIds as $tradeId) {
                        $metrics[$key]['trade_ids'][$tradeId] = true;
                    }
                    $metrics[$key]['fill_count'] = count($metrics[$key]['trade_ids']);
                } else {
                    $metrics[$key]['fill_count'] += $fillCount;
                }
                $metrics[$key]['trade_amount'] = $this->add(
                    $metrics[$key]['trade_amount'],
                    $tradeAmount
                );
            }
        }

        return $metrics;
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

    private function executionMetricKey(
        int $agentId,
        int $userType,
        string $settleAsset,
        int $symbolScope,
        string $symbol
    ): string {
        return implode(':', [
            $agentId,
            $userType,
            strtoupper(trim($settleAsset)),
            $symbolScope,
            strtoupper(trim($symbol)),
        ]);
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

    /**
     * @return int[]
     */
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
        $value = $this->decimal($value);

        return bccomp($value, '0', 16) < 0
            ? bcmul($value, '-1', 16)
            : $value;
    }

    private function decimal($value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) ? $value : '0';
    }
}
