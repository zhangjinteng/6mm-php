<?php

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\Legacy\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AgentFuturesHourlyFactService
{
    public const CALCULATION_VERSION = 13;

    private const INTEGER_FIELDS = [
        'order_count',
        'filled_order_count',
        'fill_count',
        'cancel_order_count',
        'commission_count',
        'fee_count',
        'funding_settlement_count',
    ];

    private const DECIMAL_FIELDS = [
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
        'bankruptcy_loss',
        'uncollected_liquidation_fee',
        'uncollected_trading_fee',
        'margin_change',
    ];

    private FuturesDailySourceProvider $sourceProvider;

    public function __construct(FuturesDailySourceProvider $sourceProvider)
    {
        $this->sourceProvider = $sourceProvider;
    }

    /**
     * 构建一个完整UTC小时桶。任何数据源抛出异常时，本方法整体失败。
     *
     * @param int[] $agentIds
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $hourStart, array $agentIds): array
    {
        $start = $hourStart->setTimezone('UTC')->startOfHour();
        $end = $start->addHour();
        $facts = [];
        $riskRows = [];

        foreach ([
            'orderRows',
            'fillRows',
            'cancelRows',
            'commissionRows',
            'feeRows',
            'riskRows',
            'fundingSettlementPositionRows',
            'liquidationRows',
            'bankruptcyRows',
        ] as $source) {
            // 每个数据源查询完成后立即归并并释放，避免多份明细同时驻留内存。
            $rows = $this->sourceProvider->{$source}($agentIds, $start, $end);
            if ($source === 'riskRows') {
                $riskRows = $rows;
            }
            foreach ($rows as $row) {
                $fact = &$this->fact($facts, $row);

                if ($source === 'orderRows') {
                    $fact['order_count'] += max(0, (int) ($row['order_count'] ?? 0));
                    $this->addDecimalFields($fact, $row, ['order_amount']);
                } elseif ($source === 'fillRows') {
                    $fact['fill_count'] += max(0, (int) ($row['fill_count'] ?? 0));
                    $filledOrderIds = $this->mergeIds(
                        $fact['filled_order_ids'],
                        $row['filled_order_ids'] ?? []
                    );
                    $sourceFilledOrderCount = max(
                        0,
                        (int) ($row['filled_order_count'] ?? 0)
                    );
                    if ($sourceFilledOrderCount > 0 && $filledOrderIds === []) {
                        throw new InvalidArgumentException('合约小时成交统计缺少委托去重ID');
                    }
                    $fact['execution_details'] = array_merge($fact['execution_details'] ?? [], $row['execution_details'] ?? []);
                    $fact['filled_order_ids'] = $filledOrderIds;
                    $fact['filled_order_count'] = count($filledOrderIds);
                    $fact['last_trade_at'] = $this->latestDateTime(
                        $fact['last_trade_at'],
                        $row['last_trade_at'] ?? null
                    );
                    $this->addDecimalFields($fact, $row, [
                        'trade_amount',
                        'trading_fee',
                        'realized_pnl',
                    ]);
                    $fact['liquidation_position_ids'] = $this->mergeIds(
                        $fact['liquidation_position_ids'],
                        $row['liquidation_position_ids'] ?? []
                    );
                } elseif ($source === 'cancelRows') {
                    $fact['cancel_order_count'] += max(
                        0,
                        (int) ($row['cancel_order_count'] ?? 0)
                    );
                    $this->addDecimalFields($fact, $row, ['cancel_amount']);
                } elseif ($source === 'commissionRows') {
                    $fact['commission_count'] += max(
                        0,
                        (int) ($row['commission_count'] ?? 0)
                    );
                    $this->addDecimalFields($fact, $row, ['maker_commission', 'taker_commission']);
                } elseif ($source === 'feeRows') {
                    $fact['fee_count'] += max(
                        0,
                        (int) ($row['fee_count'] ?? 0)
                    );
                    $this->addDecimalFields($fact, $row, [
                        'maker_trade_amount',
                        'taker_trade_amount',
                        'maker_handling_fee',
                        'taker_handling_fee',
                    ]);
                } elseif ($source === 'riskRows') {
                    $fact['funding_settlement_count'] += max(
                        0,
                        (int) ($row['funding_settlement_count'] ?? 0)
                    );
                    $this->addDecimalFields($fact, $row, [
                        'funding_fee',
                        'funding_fee_income',
                        'funding_fee_expense',
                        'liquidation_fee',
                        'margin_change',
                    ]);
                } elseif ($source === 'fundingSettlementPositionRows') {
                    $this->addDecimalFields($fact, $row, [
                        'funding_settlement_position_amount',
                    ]);
                } elseif ($source === 'liquidationRows') {
                    $positionId = trim((string) (
                        $row['liquidation_position_id'] ?? ''
                    ));
                    if ($positionId === '' || $positionId === '0') {
                        throw new InvalidArgumentException(
                            '合约小时强平统计缺少仓位编号'
                        );
                    }
                    $fact['liquidation_position_ids'] = $this->mergeIds(
                        $fact['liquidation_position_ids'],
                        [$positionId]
                    );
                    $positionAmount = $this->normalizeDecimal(
                        $row['liquidation_position_amount'] ?? '0'
                    );
                    $existingAmount = $fact['liquidation_position_amounts'][$positionId]
                        ?? '0';
                    if (bccomp($positionAmount, $existingAmount, 16) > 0) {
                        $fact['liquidation_position_amounts'][$positionId] = $positionAmount;
                    }
                    $this->addDecimalFields($fact, $row, [
                        'liquidation_trade_amount',
                        'liquidation_realized_pnl',
                    ]);
                } else {
                    $eventId = trim((string) ($row['bankruptcy_event_id'] ?? ''));
                    if ($eventId === '') {
                        throw new InvalidArgumentException(
                            '合约小时穿仓统计缺少账本事件编号'
                        );
                    }
                    $bankruptcyLoss = $this->absolute(
                        $row['bankruptcy_loss'] ?? '0'
                    );
                    if (bccomp($bankruptcyLoss, '0', 16) > 0) {
                        $fact['bankruptcy_event_ids'] = $this->mergeIds(
                            $fact['bankruptcy_event_ids'],
                            [$eventId]
                        );
                        $existingLoss = $fact['bankruptcy_event_amounts'][$eventId]
                            ?? '0';
                        if (bccomp($bankruptcyLoss, $existingLoss, 16) > 0) {
                            $fact['bankruptcy_event_amounts'][$eventId] = $bankruptcyLoss;
                            $fact['bankruptcy_loss'] = $this->add(
                                $fact['bankruptcy_loss'],
                                bcsub($bankruptcyLoss, $existingLoss, 16)
                            );
                        }
                    }
                    $this->addDecimalFields($fact, $row, [
                        'uncollected_liquidation_fee',
                        'uncollected_trading_fee',
                    ]);
                }

                unset($fact);
            }
            unset($rows);
        }

        // 日报特殊口径也必须限制在同一小时窗口内，禁止日报任务再次整日扫描源表。
        $executionRows = $this->executionRows(
            $this->sourceProvider->executionSummaryRows($agentIds, $start, $end)
        );
        $userPnlRows = $this->userPnlRows(
            $this->sourceProvider->userPnlSettlementRows($agentIds, $start, $end)
        );
        unset($riskRows);

        $rows = array_values($facts);
        usort($rows, static function (array $left, array $right): int {
            return strcmp(
                implode(':', [
                    $left['agent_id'],
                    $left['user_type'],
                    $left['user_id'],
                    $left['symbol'],
                    $left['settle_asset'],
                ]),
                implode(':', [
                    $right['agent_id'],
                    $right['user_type'],
                    $right['user_id'],
                    $right['symbol'],
                    $right['settle_asset'],
                ])
            );
        });

        $payload = [
            'status' => 'completed',
            'calculation_version' => self::CALCULATION_VERSION,
            'agent_ids' => array_values(array_map('intval', $agentIds)),
            'window_start' => $start->format('Y-m-d H:i:s'),
            'window_end' => $end->format('Y-m-d H:i:s'),
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'rows' => $rows,
            'execution_rows' => $executionRows,
            'user_pnl_rows' => $userPnlRows,
        ];
        $payload['checksum'] = hash(
            'sha256',
            json_encode([
                'rows' => $payload['rows'],
                'execution_rows' => $payload['execution_rows'],
                'user_pnl_rows' => $payload['user_pnl_rows'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $sourceRows
     * @return array<int, array<string, mixed>>
     */
    private function executionRows(array $sourceRows): array
    {
        $rows = [];

        foreach ($sourceRows as $sourceRow) {
            $agentId = (int) ($sourceRow['agent_id'] ?? 0);
            $userType = (int) ($sourceRow['user_type'] ?? -1);
            $symbol = strtoupper(trim((string) ($sourceRow['symbol'] ?? '')));
            if (
                $agentId < 0 ||
                $symbol === ''
            ) {
                throw new InvalidArgumentException('合约小时成交汇总缺少有效的代理商、用户类型或合约');
            }

            $key = implode(':', [$agentId, $userType, $symbol]);
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'agent_id' => $agentId,
                    'user_type' => $userType,
                    'symbol' => $symbol,
                    'settle_asset' => FuturesDailySourceProvider::SETTLE_ASSET,
                    'trade_ids' => [],
                    'fill_count' => 0,
                    'trade_amount' => $this->normalizeDecimal('0'),
                ];
            }

            $tradeIds = $this->mergeIds(
                $rows[$key]['trade_ids'],
                $sourceRow['trade_ids'] ?? []
            );
            $sourceFillCount = max(0, (int) ($sourceRow['fill_count'] ?? 0));
            if ($sourceFillCount > 0 && $tradeIds === []) {
                throw new InvalidArgumentException('合约小时成交汇总缺少成交去重ID');
            }
            $rows[$key]['trade_ids'] = $tradeIds;
            $rows[$key]['fill_count'] = $tradeIds === []
                ? $rows[$key]['fill_count'] + $sourceFillCount
                : count($tradeIds);
            $rows[$key]['trade_amount'] = $this->add(
                $rows[$key]['trade_amount'],
                $sourceRow['trade_amount'] ?? '0'
            );
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $settlementRows
     * @return array<int, array<string, mixed>>
     */
    private function userPnlRows(array $settlementRows): array
    {
        $rows = [];

        foreach ($settlementRows as $sourceRow) {

            $row = &$this->userPnlRow($rows, $sourceRow);
            $closeOrderIds = $this->mergeIds(
                $row['close_order_ids'],
                $sourceRow['close_order_ids'] ?? []
            );
            $sourceCloseOrderCount = max(
                0,
                (int) ($sourceRow['close_order_count'] ?? 0)
            );
            if ($sourceCloseOrderCount > 0 && $closeOrderIds === []) {
                throw new InvalidArgumentException('合约小时用户盈亏缺少平仓委托去重ID');
            }
            $row['close_order_ids'] = $closeOrderIds;
            $row['close_order_count'] = $closeOrderIds === []
                ? $row['close_order_count'] + $sourceCloseOrderCount
                : count($closeOrderIds);
            foreach (['close_amount', 'realized_pnl', 'trading_fee'] as $field) {
                $row[$field] = $this->add($row[$field], $sourceRow[$field] ?? '0');
            }
            unset($row);
        }

        ksort($rows);

        return array_values($rows);
    }

    /** @return array<string, mixed> */
    private function &userPnlRow(array &$rows, array $sourceRow): array
    {
        $agentId = (int) ($sourceRow['agent_id'] ?? 0);
        $userType = (int) ($sourceRow['user_type'] ?? 0);
        $userId = (int) ($sourceRow['user_id'] ?? 0);
        $settleAsset = strtoupper(trim((string) (
            $sourceRow['settle_asset'] ??
            $sourceRow['asset_type'] ??
            FuturesDailySourceProvider::SETTLE_ASSET
        )));
        if (
            $agentId < 0 ||
            $userId <= 0 ||
            $settleAsset === ''
        ) {
            throw new InvalidArgumentException('合约小时用户盈亏缺少有效的代理商、用户或结算币种');
        }

        $key = implode(':', [$agentId, $userType, $userId, $settleAsset]);
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'agent_id' => $agentId,
                'user_type' => $userType,
                'user_id' => $userId,
                'settle_asset' => $settleAsset,
                'close_order_ids' => [],
                'close_order_count' => 0,
                'close_amount' => $this->normalizeDecimal('0'),
                'realized_pnl' => $this->normalizeDecimal('0'),
                'trading_fee' => $this->normalizeDecimal('0'),
                'funding_fee' => $this->normalizeDecimal('0'),
                'liquidation_fee' => $this->normalizeDecimal('0'),
            ];
        }

        return $rows[$key];
    }

    /**
     * 当前持仓是时点快照，独立保存，不能与小时桶相加。
     *
     * @param int[] $agentIds
     * @return array<string, mixed>
     */
    public function currentPositionSnapshot(
        array $agentIds,
        ?CarbonImmutable $snapshotAt = null
    ): array {
        $snapshotAt = ($snapshotAt ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $facts = [];

        foreach ($this->sourceProvider->currentPositionRows($agentIds) as $row) {
            $row['settle_asset'] = FuturesDailySourceProvider::SETTLE_ASSET;
            $fact = &$this->fact($facts, $row);
            foreach ([
                'closing_position_amount',
                'long_position_amount',
                'short_position_amount',
                'occupied_margin',
                'unrealized_pnl',
            ] as $field) {
                $fact[$field] = $this->add(
                    $fact[$field] ?? '0',
                    $row[$field] ?? '0'
                );
            }
            unset($fact);
        }

        $rows = array_map(static function (array $row): array {
            return [
                'agent_id' => $row['agent_id'],
                'user_type' => $row['user_type'],
                'user_id' => $row['user_id'],
                'symbol' => $row['symbol'],
                'settle_asset' => $row['settle_asset'],
                'closing_position_amount' => $row['closing_position_amount'],
                'long_position_amount' => $row['long_position_amount'],
                'short_position_amount' => $row['short_position_amount'],
                'occupied_margin' => $row['occupied_margin'],
                'unrealized_pnl' => $row['unrealized_pnl'],
            ];
        }, array_values($facts));

        return [
            'status' => 'completed',
            'calculation_version' => self::CALCULATION_VERSION,
            'snapshot_at' => $snapshotAt->format('Y-m-d H:i:s.u'),
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
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
            $agentId < 0 ||
            $userId <= 0
        ) {
            throw new InvalidArgumentException('合约小时事实源数据缺少有效的代理商、用户或用户类型');
        }

        if ($symbol === '') {
            $symbol = FuturesDailySourceProvider::UNASSIGNED_SYMBOL;
        }
        if ($settleAsset === '') {
            $settleAsset = FuturesDailySourceProvider::SETTLE_ASSET;
        }

        $key = implode(':', [$agentId, $userType, $userId, $symbol, $settleAsset]);
        if (!isset($facts[$key])) {
            $facts[$key] = [
                'agent_id' => $agentId,
                'user_type' => $userType,
                'user_id' => $userId,
                'symbol' => $symbol,
                'settle_asset' => $settleAsset,
                'liquidation_position_ids' => [],
                'liquidation_position_amounts' => [],
                'bankruptcy_event_ids' => [],
                'bankruptcy_event_amounts' => [],
                'filled_order_ids' => [],
                'last_trade_at' => null,
                'closing_position_amount' => $this->normalizeDecimal('0'),
                'long_position_amount' => $this->normalizeDecimal('0'),
                'short_position_amount' => $this->normalizeDecimal('0'),
                'occupied_margin' => $this->normalizeDecimal('0'),
                'unrealized_pnl' => $this->normalizeDecimal('0'),
            ];
            foreach (self::INTEGER_FIELDS as $field) {
                $facts[$key][$field] = 0;
            }
            foreach (self::DECIMAL_FIELDS as $field) {
                $facts[$key][$field] = $this->normalizeDecimal('0');
            }
        }

        return $facts[$key];
    }

    private function addDecimalFields(array &$fact, array $row, array $fields): void
    {
        foreach ($fields as $field) {
            $fact[$field] = $this->add($fact[$field], $row[$field] ?? '0');
        }
    }

    private function latestDateTime($existing, $candidate): ?string
    {
        $existing = trim((string) $existing);
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return $existing === '' ? null : $existing;
        }

        return $existing === '' || strcmp($candidate, $existing) > 0
            ? $candidate
            : $existing;
    }

    private function mergeIds(array $existing, $incoming): array
    {
        if (is_string($incoming)) {
            $decoded = json_decode($incoming, true);
            $incoming = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($incoming)) {
            $incoming = [];
        }

        $ids = [];
        foreach (array_merge($existing, $incoming) as $positionId) {
            $positionId = trim((string) $positionId);
            if ($positionId !== '' && $positionId !== '0') {
                $ids[$positionId] = $positionId;
            }
        }
        ksort($ids, SORT_NATURAL);

        return array_values($ids);
    }

    private function add($left, $right): string
    {
        return bcadd($this->decimal($left), $this->decimal($right), 16);
    }

    private function absolute($value): string
    {
        $decimal = $this->normalizeDecimal($value);

        return bccomp($decimal, '0', 16) < 0
            ? bcsub('0', $decimal, 16)
            : $decimal;
    }

    private function normalizeDecimal($value): string
    {
        return bcadd($this->decimal($value), '0', 16);
    }

    private function decimal($value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) ? $value : '0';
    }
}
