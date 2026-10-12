<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\Legacy\Models\Agent;
use SixMm\Shared\FuturesReports\Legacy\Models\AgentFuturesUserSymbolDaily;
use SixMm\Shared\FuturesReports\Legacy\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\ReportUserScope;

class AgentFuturesDailyFactService
{
    private const CALCULATION_VERSION = 6;

    private const POSITION_AMOUNT_FIELDS = [
        'closing_position_amount',
        'long_position_amount',
        'short_position_amount',
        'occupied_margin',
        'unrealized_pnl',
    ];

    private FuturesDailySourceProvider $sourceProvider;

    public function __construct(FuturesDailySourceProvider $sourceProvider)
    {
        $this->sourceProvider = $sourceProvider;
    }

    /**
     * 按UTC自然日重建用户-合约事实数据。
     *
     * @return int 写入事实行数
     */
    public function aggregate(?string $date = null): int
    {
        $statDate = $this->parseDate($date);
        $start = $statDate->startOfDay();
        $end = $start->addDay();
        $agentIds = $this->mainAgentIds();

        if (empty($agentIds)) {
            return 0;
        }

        $facts = [];
        $isCurrentDay = $statDate->equalTo(CarbonImmutable::now('UTC')->startOfDay());

        if (!$isCurrentDay) {
            $this->mergeExistingPositionSnapshots($facts, $agentIds, $statDate);
        }

        foreach ($this->sourceProvider->orderRows($agentIds, $start, $end) as $row) {
            $fact = &$this->fact($facts, $row);
            $fact['order_count'] += max(0, (int) ($row['order_count'] ?? 0));
            $fact['order_amount'] = $this->add(
                $fact['order_amount'],
                $row['order_amount'] ?? '0'
            );
            unset($fact);
        }

        foreach ($this->sourceProvider->fillRows($agentIds, $start, $end) as $row) {
            $fact = &$this->fact($facts, $row);
            $fact['fill_count'] += max(0, (int) ($row['fill_count'] ?? 0));
            $fact['filled_order_count'] += max(
                0,
                (int) ($row['filled_order_count'] ?? 0)
            );
            $fact['trade_amount'] = $this->add(
                $fact['trade_amount'],
                $row['trade_amount'] ?? '0'
            );
            $fact['trading_fee'] = $this->add(
                $fact['trading_fee'],
                $row['trading_fee'] ?? '0'
            );
            $fact['realized_pnl'] = $this->add(
                $fact['realized_pnl'],
                $row['realized_pnl'] ?? '0'
            );
            unset($fact);
        }

        foreach ($this->sourceProvider->cancelRows($agentIds, $start, $end) as $row) {
            $fact = &$this->fact($facts, $row);
            $fact['cancel_order_count'] += max(
                0,
                (int) ($row['cancel_order_count'] ?? 0)
            );
            $fact['cancel_amount'] = $this->add(
                $fact['cancel_amount'],
                $row['cancel_amount'] ?? '0'
            );
            unset($fact);
        }

        foreach ($this->sourceProvider->commissionRows($agentIds, $start, $end) as $row) {
            $fact = &$this->fact($facts, $row);
            $fact['commission_count'] += max(0, (int) ($row['commission_count'] ?? 0));
            $fact['fee_count'] += max(0, (int) ($row['fee_count'] ?? 0));
            $fact['maker_commission'] = $this->add(
                $fact['maker_commission'],
                $row['maker_commission'] ?? '0'
            );
            $fact['taker_commission'] = $this->add(
                $fact['taker_commission'],
                $row['taker_commission'] ?? '0'
            );
            foreach ([
                'maker_trade_amount',
                'taker_trade_amount',
                'maker_handling_fee',
                'taker_handling_fee',
            ] as $field) {
                $fact[$field] = $this->add($fact[$field], $row[$field] ?? '0');
            }
            unset($fact);
        }

        foreach ($this->sourceProvider->riskRows($agentIds, $start, $end) as $row) {
            $fact = &$this->fact($facts, $row);
            $fact['funding_settlement_count'] += max(
                0,
                (int) ($row['funding_settlement_count'] ?? 0)
            );
            $fact['funding_fee'] = $this->add(
                $fact['funding_fee'],
                $row['funding_fee'] ?? '0'
            );
            $fact['funding_fee_income'] = $this->add(
                $fact['funding_fee_income'],
                $row['funding_fee_income'] ?? '0'
            );
            $fact['funding_fee_expense'] = $this->add(
                $fact['funding_fee_expense'],
                $row['funding_fee_expense'] ?? '0'
            );
            $fact['liquidation_fee'] = $this->add(
                $fact['liquidation_fee'],
                $row['liquidation_fee'] ?? '0'
            );
            $fact['margin_change'] = $this->add(
                $fact['margin_change'],
                $row['margin_change'] ?? '0'
            );
            unset($fact);
        }

        foreach (
            $this->sourceProvider->fundingSettlementPositionRows(
                $agentIds,
                $start,
                $end
            ) as $row
        ) {
            $fact = &$this->fact($facts, $row);
            $fact['funding_settlement_position_amount'] = $this->add(
                $fact['funding_settlement_position_amount'],
                $row['funding_settlement_position_amount'] ?? '0'
            );
            unset($fact);
        }

        foreach ($this->sourceProvider->liquidationRows($agentIds, $start, $end) as $row) {
            $row['settle_asset'] = FuturesDailySourceProvider::SETTLE_ASSET;
            $fact = &$this->fact($facts, $row);
            $fact['liquidation_count']++;
            $fact['liquidation_position_amount'] = $this->add(
                $fact['liquidation_position_amount'],
                $row['liquidation_position_amount'] ?? '0'
            );
            $fact['liquidation_trade_amount'] = $this->add(
                $fact['liquidation_trade_amount'],
                $row['liquidation_trade_amount'] ?? '0'
            );
            $fact['liquidation_realized_pnl'] = $this->add(
                $fact['liquidation_realized_pnl'],
                $row['liquidation_realized_pnl'] ?? '0'
            );
            unset($fact);
        }

        foreach ($this->sourceProvider->bankruptcyRows($agentIds, $start, $end) as $row) {
            $row['settle_asset'] = FuturesDailySourceProvider::SETTLE_ASSET;
            $eventId = trim((string) ($row['bankruptcy_event_id'] ?? ''));
            if ($eventId === '') {
                throw new InvalidArgumentException('合约日穿仓统计缺少账本事件编号');
            }
            $fact = &$this->fact($facts, $row);
            $bankruptcyLoss = $this->decimal($row['bankruptcy_loss'] ?? '0');
            if (bccomp($bankruptcyLoss, '0', 16) > 0) {
                $fact['bankruptcy_count']++;
            }
            $fact['bankruptcy_loss'] = $this->add(
                $fact['bankruptcy_loss'],
                $bankruptcyLoss
            );
            $fact['uncollected_liquidation_fee'] = $this->add(
                $fact['uncollected_liquidation_fee'],
                $row['uncollected_liquidation_fee'] ?? '0'
            );
            $fact['uncollected_trading_fee'] = $this->add(
                $fact['uncollected_trading_fee'],
                $row['uncollected_trading_fee'] ?? '0'
            );
            unset($fact);
        }

        if ($isCurrentDay) {
            $snapshotAt = CarbonImmutable::now('UTC');
            foreach ($this->sourceProvider->currentPositionRows($agentIds) as $row) {
                $row['settle_asset'] = FuturesDailySourceProvider::SETTLE_ASSET;
                // Shared buckets include direct platform users; legacy tables stay merchant-only.
                if (!in_array((int) ($row['agent_id'] ?? -1), $agentIds, true)) continue;
                $fact = &$this->fact($facts, $row);
                $this->mergePositionAmounts($fact, $row);
                $fact['position_snapshot_at'] = $snapshotAt;
                unset($fact);
            }
        }

        $now = CarbonImmutable::now('UTC');
        $rows = array_map(
            static fn (array $fact): array => array_merge($fact, [
                'stat_date' => $statDate->toDateString(),
                'calculation_version' => self::CALCULATION_VERSION,
                'last_aggregated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $this->merchantRows(array_values($facts))
        );

        DB::transaction(static function () use ($agentIds, $statDate, $rows): void {
            AgentFuturesUserSymbolDaily::query()
                ->whereIn('agent_id', $agentIds)
                ->where('stat_date', $statDate->toDateString())
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                AgentFuturesUserSymbolDaily::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * 聚合今天和昨天，覆盖晚到成交、佣金及账变。
     *
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
     * 将同一UTC自然日的完整小时桶聚合为现有用户-合约日事实。
     *
     * 小时指标采用求和；强平次数按仓位编号跨小时去重；持仓价值采用
     * 独立的时点快照，绝不参与小时累加。
     *
     * @param iterable<int, array<string, mixed>> $buckets
     * @param array<string, mixed>|null $positionSnapshot
     */
    public function persistHourlyBuckets(
        string $date,
        iterable $buckets,
        ?array $positionSnapshot = null
    ): int {
        $statDate = $this->parseDate($date);
        $dayStart = $statDate->startOfDay();
        $dayEnd = $dayStart->addDay();
        $agentIds = $this->mainAgentIds();

        if (empty($agentIds)) {
            return 0;
        }

        $facts = [];
        $liquidationPositionIds = [];
        $liquidationPositionAmounts = [];
        $bankruptcyEventAmounts = [];
        $filledOrderIds = [];
        $seenWindows = [];

        if ($positionSnapshot === null && $statDate->lessThan(
            CarbonImmutable::now('UTC')->startOfDay()
        )) {
            $this->mergeExistingPositionSnapshots($facts, $agentIds, $statDate);
        }

        foreach ($buckets as $bucket) {
            $this->validateHourBucket($bucket, $dayStart, $dayEnd);
            $windowStart = (string) $bucket['window_start'];
            if (isset($seenWindows[$windowStart])) {
                throw new InvalidArgumentException('合约小时事实桶存在重复时间窗口');
            }
            $seenWindows[$windowStart] = true;

            $merchantRows = [];
            foreach (($bucket['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('合约小时事实行格式无效');
                }
                // Shared caches retain white-label direct users; legacy reports are merchant-only.
                if (!in_array((int) ($row['agent_id'] ?? -1), $agentIds, true)) continue;
                $merchantRows[] = $row;
            }
            foreach ($this->merchantRows($merchantRows) as $row) {
                $fact = &$this->fact($facts, $row);
                $fact['order_count'] += max(0, (int) ($row['order_count'] ?? 0));
                $fact['fill_count'] += max(0, (int) ($row['fill_count'] ?? 0));
                $fact['cancel_order_count'] += max(
                    0,
                    (int) ($row['cancel_order_count'] ?? 0)
                );
                $fact['commission_count'] += max(
                    0,
                    (int) ($row['commission_count'] ?? 0)
                );
                $fact['fee_count'] += max(0, (int) ($row['fee_count'] ?? 0));
                $fact['funding_settlement_count'] += max(
                    0,
                    (int) ($row['funding_settlement_count'] ?? 0)
                );

                foreach ([
                    'order_amount',
                    'trade_amount',
                    'cancel_amount',
                    'trading_fee',
                    'maker_commission',
                    'taker_commission',
                    'maker_trade_amount',
                    'taker_trade_amount',
                    'maker_handling_fee',
                    'taker_handling_fee',
                    'realized_pnl',
                    'funding_fee',
                    'funding_settlement_position_amount',
                    'funding_fee_income',
                    'funding_fee_expense',
                    'liquidation_fee',
                    'liquidation_trade_amount',
                    'liquidation_realized_pnl',
                    'uncollected_liquidation_fee',
                    'uncollected_trading_fee',
                    'margin_change',
                ] as $field) {
                    $fact[$field] = $this->add($fact[$field], $row[$field] ?? '0');
                }

                $factKey = $this->factKey($fact);
                foreach ($this->positionIds($row['filled_order_ids'] ?? []) as $id) {
                    $filledOrderIds[$factKey][$id] = true;
                }
                if (
                    (int) ($row['filled_order_count'] ?? 0) > 0 &&
                    empty($filledOrderIds[$factKey])
                ) {
                    throw new InvalidArgumentException('合约日事实缺少成交委托去重ID');
                }
                foreach ($this->positionIds($row['liquidation_position_ids'] ?? []) as $id) {
                    $liquidationPositionIds[$factKey][$id] = true;
                }
                foreach (($row['liquidation_position_amounts'] ?? []) as $id => $amount) {
                    $id = trim((string) $id);
                    if ($id === '' || $id === '0') {
                        continue;
                    }
                    $amount = $this->decimal($amount);
                    $existing = $liquidationPositionAmounts[$factKey][$id] ?? '0';
                    if (bccomp($amount, $existing, 16) > 0) {
                        $liquidationPositionAmounts[$factKey][$id] = $amount;
                    }
                }
                foreach (($row['bankruptcy_event_amounts'] ?? []) as $id => $amount) {
                    $id = trim((string) $id);
                    if ($id === '') {
                        continue;
                    }
                    $amount = $this->decimal($amount);
                    $existing = $bankruptcyEventAmounts[$factKey][$id] ?? '0';
                    if (bccomp($amount, $existing, 16) > 0) {
                        $bankruptcyEventAmounts[$factKey][$id] = $amount;
                    }
                }
                unset($fact);
            }
            unset($bucket, $row, $merchantRows);
        }

        foreach ($facts as $key => &$fact) {
            $fact['filled_order_count'] = count($filledOrderIds[$key] ?? []);
            $fact['liquidation_count'] = count($liquidationPositionIds[$key] ?? []);
            $fact['liquidation_position_amount'] = '0';
            foreach ($liquidationPositionAmounts[$key] ?? [] as $amount) {
                $fact['liquidation_position_amount'] = $this->add(
                    $fact['liquidation_position_amount'],
                    $amount
                );
            }
            $fact['bankruptcy_count'] = count($bankruptcyEventAmounts[$key] ?? []);
            $fact['bankruptcy_loss'] = '0';
            foreach ($bankruptcyEventAmounts[$key] ?? [] as $amount) {
                $fact['bankruptcy_loss'] = $this->add(
                    $fact['bankruptcy_loss'],
                    $amount
                );
            }
        }
        unset($fact);

        if ($positionSnapshot !== null) {
            if (($positionSnapshot['status'] ?? null) !== 'completed') {
                throw new InvalidArgumentException('当前持仓快照未完成');
            }
            $snapshotAtValue = trim((string) ($positionSnapshot['snapshot_at'] ?? ''));
            if ($snapshotAtValue === '') {
                throw new InvalidArgumentException('当前持仓快照缺少快照时间');
            }
            try {
                $snapshotAt = CarbonImmutable::parse($snapshotAtValue, 'UTC');
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('当前持仓快照时间格式无效', 0, $e);
            }

            $snapshotRows = [];
            foreach (($positionSnapshot['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('当前持仓快照行格式无效');
                }
                if (!in_array((int) ($row['agent_id'] ?? -1), $agentIds, true)) continue;
                $snapshotRows[] = $row;
            }
            foreach ($this->merchantRows($snapshotRows) as $row) {
                $fact = &$this->fact($facts, $row);
                $this->mergePositionAmounts($fact, $row);
                $fact['position_snapshot_at'] = $snapshotAt;
                unset($fact);
            }
        }

        $now = CarbonImmutable::now('UTC');
        $rows = array_map(
            static fn (array $fact): array => array_merge($fact, [
                'stat_date' => $statDate->toDateString(),
                'calculation_version' => AgentFuturesHourlyFactService::CALCULATION_VERSION,
                'last_aggregated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $this->merchantRows(array_values($facts))
        );

        $this->replaceRows($agentIds, $statDate, $rows);

        return count($rows);
    }

    /**
     * UTC零点使用同一份当前持仓关闭前一统计日。
     *
     * @return int 写入持仓快照的用户合约行数
     */
    public function snapshotBoundary(?string $date = null, ?array $snapshot = null): int
    {
        $boundaryDate = $this->parseDate($date);
        $boundaryAt = $boundaryDate->startOfDay();
        $previousDate = $boundaryDate->subDay()->toDateString();
        $agentIds = $this->mainAgentIds();

        if (empty($agentIds)) {
            return 0;
        }

        $positions = [];
        if ($snapshot !== null && (($snapshot['status'] ?? null) !== 'completed' || !is_array($snapshot['rows'] ?? null))) {
            throw new InvalidArgumentException('持仓边界快照无效');
        }
        $sourceRows = $snapshot === null ? $this->sourceProvider->currentPositionRows($agentIds) : $snapshot['rows'];
        foreach ($this->merchantRows($sourceRows) as $row) {
            $row['settle_asset'] = FuturesDailySourceProvider::SETTLE_ASSET;
            $fact = &$this->fact($positions, $row);
            $this->mergePositionAmounts($fact, $row);
            $fact['position_snapshot_at'] = $boundaryAt;
            unset($fact);
        }

        $now = CarbonImmutable::now('UTC');
        $rows = array_map(
            static fn (array $fact): array => array_merge($fact, [
                'stat_date' => $previousDate,
                'calculation_version' => self::CALCULATION_VERSION,
                'last_aggregated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            array_values($positions)
        );

        DB::transaction(static function () use (
            $agentIds,
            $previousDate,
            $boundaryAt,
            $rows
        ): void {
            AgentFuturesUserSymbolDaily::query()
                ->whereIn('agent_id', $agentIds)
                ->where('stat_date', $previousDate)
                ->update([
                    'closing_position_amount' => '0',
                    'long_position_amount' => '0',
                    'short_position_amount' => '0',
                    'occupied_margin' => '0',
                    'unrealized_pnl' => '0',
                    'position_snapshot_at' => $boundaryAt,
                    'updated_at' => CarbonImmutable::now('UTC'),
                ]);

            foreach (array_chunk($rows, 500) as $chunk) {
                AgentFuturesUserSymbolDaily::query()->upsert(
                    $chunk,
                    [
                        'agent_id',
                        'stat_date',
                        'user_type',
                        'user_id',
                        'symbol',
                        'settle_asset',
                    ],
                    [
                        'closing_position_amount',
                        'long_position_amount',
                        'short_position_amount',
                        'occupied_margin',
                        'unrealized_pnl',
                        'position_snapshot_at',
                        'calculation_version',
                        'last_aggregated_at',
                        'updated_at',
                    ]
                );
            }
        });

        return count($rows);
    }

    private function mergeExistingPositionSnapshots(
        array &$facts,
        array $agentIds,
        CarbonImmutable $statDate
    ): void {
        $rows = AgentFuturesUserSymbolDaily::query()
            ->whereIn('agent_id', $agentIds)
            ->where('stat_date', $statDate->toDateString())
            ->whereNotNull('position_snapshot_at')
            ->get([
                'agent_id',
                'user_type',
                'user_id',
                'symbol',
                'settle_asset',
                'closing_position_amount',
                'long_position_amount',
                'short_position_amount',
                'occupied_margin',
                'unrealized_pnl',
                'position_snapshot_at',
            ]);

        foreach ($this->merchantRows($rows->map(static fn ($row): array => $row->toArray())->all()) as $payload) {
            $fact = &$this->fact($facts, $payload);
            foreach (self::POSITION_AMOUNT_FIELDS as $field) {
                $fact[$field] = $this->decimal($payload[$field] ?? '0');
            }
            $fact['position_snapshot_at'] = $payload['position_snapshot_at'];
            unset($fact);
        }
    }

    /** @param array<string, mixed> $fact @param array<string, mixed> $row */
    private function mergePositionAmounts(array &$fact, array $row): void
    {
        foreach (self::POSITION_AMOUNT_FIELDS as $field) {
            $fact[$field] = $this->add(
                $fact[$field] ?? '0',
                $row[$field] ?? '0'
            );
        }
    }

    /** @param array<string, mixed> $bucket */
    private function validateHourBucket(
        array $bucket,
        CarbonImmutable $dayStart,
        CarbonImmutable $dayEnd
    ): void {
        if (($bucket['status'] ?? null) !== 'completed') {
            throw new InvalidArgumentException('存在未完成的合约小时事实桶');
        }

        try {
            $start = CarbonImmutable::parse((string) ($bucket['window_start'] ?? ''), 'UTC');
            $end = CarbonImmutable::parse((string) ($bucket['window_end'] ?? ''), 'UTC');
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('合约小时事实桶时间格式无效', 0, $e);
        }

        if (
            !$start->equalTo($start->startOfHour()) ||
            !$end->equalTo($start->addHour()) ||
            $start->lessThan($dayStart) ||
            !$start->lessThan($dayEnd)
        ) {
            throw new InvalidArgumentException('合约小时事实桶不属于指定UTC统计日');
        }
    }

    /** @return string[] */
    private function positionIds($value): array
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

    /** @param array<string, mixed> $fact */
    private function factKey(array $fact): string
    {
        return implode(':', [
            $fact['agent_id'],
            $fact['user_type'],
            $fact['user_id'],
            $fact['symbol'],
            $fact['settle_asset'],
        ]);
    }

    /**
     * @param int[] $agentIds
     * @param array<int, array<string, mixed>> $rows
     */
    private function replaceRows(
        array $agentIds,
        CarbonImmutable $statDate,
        array $rows
    ): void {
        DB::transaction(static function () use ($agentIds, $statDate, $rows): void {
            AgentFuturesUserSymbolDaily::query()
                ->whereIn('agent_id', $agentIds)
                ->where('stat_date', $statDate->toDateString())
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                AgentFuturesUserSymbolDaily::query()->insert($chunk);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function &fact(array &$facts, array $row): array
    {
        $agentId = (int) ($row['agent_id'] ?? 0);
        $userType = (int) ($row['user_type'] ?? 0);
        $userId = (int) ($row['user_id'] ?? $row['platform_user_id'] ?? 0);
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
        $settleAsset = strtoupper(trim((string) (
            $row['settle_asset'] ??
            $row['fee_asset'] ??
            $row['asset_type'] ??
            FuturesDailySourceProvider::SETTLE_ASSET
        )));

        if (
            $agentId <= 0 ||
            !in_array($userType, [User::USER_TYPE_NORMAL, User::USER_TYPE_SIMULATE], true) ||
            $userId <= 0
        ) {
            throw new InvalidArgumentException('合约日报源数据缺少有效的代理商、用户或用户类型');
        }

        if ($symbol === '') {
            $symbol = FuturesDailySourceProvider::UNASSIGNED_SYMBOL;
        }
        if ($settleAsset === '') {
            $settleAsset = FuturesDailySourceProvider::SETTLE_ASSET;
        }

        $key = implode(':', [
            $agentId,
            $userType,
            $userId,
            $symbol,
            $settleAsset,
        ]);

        if (!isset($facts[$key])) {
            $facts[$key] = [
                'agent_id' => $agentId,
                'user_type' => $userType,
                'user_id' => $userId,
                'symbol' => $symbol,
                'settle_asset' => $settleAsset,
                'order_count' => 0,
                'order_amount' => '0',
                'filled_order_count' => 0,
                'fill_count' => 0,
                'trade_amount' => '0',
                'cancel_order_count' => 0,
                'cancel_amount' => '0',
                'trading_fee' => '0',
                'commission_count' => 0,
                'maker_commission' => '0',
                'taker_commission' => '0',
                'fee_count' => 0,
                'maker_trade_amount' => '0',
                'taker_trade_amount' => '0',
                'maker_handling_fee' => '0',
                'taker_handling_fee' => '0',
                'realized_pnl' => '0',
                'funding_fee' => '0',
                'funding_settlement_count' => 0,
                'funding_settlement_position_amount' => '0',
                'funding_fee_income' => '0',
                'funding_fee_expense' => '0',
                'liquidation_count' => 0,
                'liquidation_fee' => '0',
                'liquidation_position_amount' => '0',
                'liquidation_trade_amount' => '0',
                'liquidation_realized_pnl' => '0',
                'bankruptcy_count' => 0,
                'bankruptcy_loss' => '0',
                'uncollected_liquidation_fee' => '0',
                'uncollected_trading_fee' => '0',
                'margin_change' => '0',
                'closing_position_amount' => '0',
                'long_position_amount' => '0',
                'short_position_amount' => '0',
                'occupied_margin' => '0',
                'unrealized_pnl' => '0',
                'position_snapshot_at' => null,
            ];
        }

        return $facts[$key];
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

    private function merchantRows(array $rows): array
    {
        if ($rows === []) return [];
        return ReportUserScope::fromRows(DB::connection(), $rows)->filterMerchants($rows);
    }

    private function add($left, $right): string
    {
        return bcadd($this->decimal($left), $this->decimal($right), 16);
    }

    private function decimal($value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) ? $value : '0';
    }
}
