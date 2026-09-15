<?php

declare(strict_types=1);

namespace SixMm\Shared\Hedging;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use SixMm\Shared\Contracts\UserDataScope;

final class HedgingMonitorQueryService
{
    public const STATUS_LABELS = [
        'unconfigured' => '未配置币种对冲',
        'global_off' => '总开关已关闭',
        'symbol_off' => '币种已关闭',
        'account_unavailable' => '账户不可用',
        'observing' => '观察中',
        'data_stale' => '数据已过期',
        'open_required' => '待首次对冲',
        'rebalance_required' => '待再平衡',
        'exit_required' => '待退出',
        'balanced' => '对冲正常',
        'execution_failed' => '执行失败',
    ];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return array<string, mixed> */
    public function search(HedgingMonitorQuery $criteria, UserDataScope $scope): array
    {
        $configured = $this->configuredRows();
        $scope->apply($configured, 'monitor.agent_id');

        $unconfigured = $this->unconfiguredRows();
        $scope->apply($unconfigured, 'exposure.agent_id');

        $scopedRows = $this->connection->query()
            ->fromSub($configured->unionAll($unconfigured), 'monitor_rows');

        $summary = $this->summary((clone $scopedRows)->get([
            'agent_id', 'source', 'symbol', 'net_notional_usdt',
            'target_hedge_usdt', 'actual_hedge_usdt', 'calculated_at',
        ]));

        $query = clone $scopedRows;
        if ($criteria->keyword() !== '') {
            $like = '%' . $this->escapeLike($criteria->keyword()) . '%';
            $query->where(static function (Builder $nested) use ($like): void {
                $nested->whereRaw('UPPER(symbol) LIKE ?', [$like])
                    ->orWhereRaw('UPPER(exchange) LIKE ?', [$like])
                    ->orWhereRaw('UPPER(account_name) LIKE ?', [$like]);
            });
        }
        if ($criteria->status() !== '') {
            $query->where('status', $criteria->status());
        }
        if ($criteria->globalEnabled() !== '') {
            $query->where('global_enabled', (int) $criteria->globalEnabled());
        }
        if ($criteria->symbolEnabled() !== '') {
            $query->where('symbol_enabled', (int) $criteria->symbolEnabled());
        }

        $total = (clone $query)->count();
        $rows = $query
            ->select(array_merge(['agent_id'], $this->resultColumns()))
            ->orderBy('agent_id')
            ->orderBy('symbol')
            ->orderBy('config_id')
            ->forPage($criteria->page(), $criteria->pageSize())
            ->get()
            ->map(fn (object $row): array => $this->mapRow($row))
            ->values()
            ->all();

        return [
            'count' => $total,
            'lists' => $rows,
            'summary' => $summary,
            'options' => [
                'statuses' => collect(self::STATUS_LABELS)
                    ->map(fn (string $label, string $value): array => compact('label', 'value'))
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function configuredRows(): Builder
    {
        return $this->connection->table('hedge_monitor_snapshots as monitor')
            ->leftJoin('hedge_configs as config', 'config.id', '=', 'monitor.config_id')
            ->leftJoin('hedging_settings as setting', 'setting.agent_id', '=', 'monitor.agent_id')
            ->select(array_map(
                static fn (string $column): string => 'monitor.' . $column,
                array_merge(['agent_id'], $this->resultColumns())
            ))
            ->selectRaw('CASE WHEN setting.enabled = ? THEN 1 ELSE 0 END AS global_enabled', [true])
            ->selectRaw('CASE WHEN config.enabled = ? THEN 1 ELSE 0 END AS symbol_enabled', [true]);
    }

    private function unconfiguredRows(): Builder
    {
        return $this->connection->table('exposure_snapshots as exposure')
            ->leftJoin('hedging_settings as setting', 'setting.agent_id', '=', 'exposure.agent_id')
            ->where('exposure.net_quantity', '<>', 0)
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('hedge_monitor_snapshots as configured')
                    ->whereColumn('configured.agent_id', 'exposure.agent_id')
                    ->whereRaw('LOWER(TRIM(configured.source)) = LOWER(TRIM(exposure.source))')
                    ->whereRaw('UPPER(TRIM(configured.symbol)) = UPPER(TRIM(exposure.symbol))');
            })
            ->selectRaw("exposure.agent_id,
                -exposure.id AS id,
                0 AS config_id,
                0 AS exchange_account_id,
                exposure.source,
                exposure.symbol,
                '' AS target_symbol,
                '' AS exchange,
                '' AS account_name,
                exposure.net_quantity,
                exposure.long_quantity,
                exposure.short_quantity,
                exposure.net_notional_usdt,
                0 AS target_hedge_usdt,
                0 AS target_hedge_quantity,
                0 AS actual_hedge_usdt,
                0 AS actual_hedge_quantity,
                'unconfigured' AS switch_status,
                'ok' AS health_status,
                'balanced' AS action_status,
                'unconfigured' AS status,
                '该交易对尚未配置币种对冲' AS status_reason,
                exposure.observed_at AS exposure_observed_at,
                NULL AS position_observed_at,
                exposure.observed_at AS calculated_at,
                exposure.updated_at,
                CASE WHEN setting.enabled = true THEN 1 ELSE 0 END AS global_enabled,
                NULL AS symbol_enabled");
    }

    /** @return string[] */
    private function resultColumns(): array
    {
        return [
            'id', 'config_id', 'exchange_account_id', 'source', 'symbol',
            'target_symbol', 'exchange', 'account_name', 'net_quantity',
            'long_quantity', 'short_quantity', 'net_notional_usdt',
            'target_hedge_usdt', 'target_hedge_quantity', 'actual_hedge_usdt',
            'actual_hedge_quantity', 'switch_status', 'health_status',
            'action_status', 'status', 'status_reason', 'exposure_observed_at',
            'position_observed_at', 'calculated_at', 'updated_at',
        ];
    }

    /** @return array<string, mixed> */
    private function mapRow(object $row): array
    {
        $status = (string) $row->status;
        return [
            'agent_id' => (int) $row->agent_id,
            'id' => (int) $row->id,
            'config_id' => (int) $row->config_id,
            'exchange_account_id' => (int) $row->exchange_account_id,
            'source' => (string) $row->source,
            'symbol' => (string) $row->symbol,
            'target_symbol' => (string) $row->target_symbol,
            'exchange' => (string) $row->exchange,
            'account_name' => (string) $row->account_name,
            'net_quantity' => (string) $row->net_quantity,
            'long_quantity' => (string) $row->long_quantity,
            'short_quantity' => (string) $row->short_quantity,
            'net_notional_usdt' => (string) $row->net_notional_usdt,
            'target_hedge_usdt' => (string) $row->target_hedge_usdt,
            'target_hedge_quantity' => (string) $row->target_hedge_quantity,
            'actual_hedge_usdt' => (string) $row->actual_hedge_usdt,
            'actual_hedge_quantity' => (string) $row->actual_hedge_quantity,
            'switch_status' => (string) $row->switch_status,
            'health_status' => (string) $row->health_status,
            'action_status' => (string) $row->action_status,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? $status,
            'status_reason' => (string) $row->status_reason,
            'exposure_observed_at' => $row->exposure_observed_at !== null ? (string) $row->exposure_observed_at : null,
            'position_observed_at' => $row->position_observed_at !== null ? (string) $row->position_observed_at : null,
            'calculated_at' => (string) $row->calculated_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Collection $rows): array
    {
        $net = $target = $actual = 0.0;
        $seen = [];
        $calculatedAt = null;
        foreach ($rows as $row) {
            $key = (int) $row->agent_id . '|' . strtolower(trim((string) $row->source))
                . '|' . strtoupper(trim((string) $row->symbol));
            if (!isset($seen[$key])) {
                $net += abs((float) $row->net_notional_usdt);
                $seen[$key] = true;
            }
            $target += abs((float) $row->target_hedge_usdt);
            $actual += abs((float) $row->actual_hedge_usdt);
            $current = $row->calculated_at !== null ? (string) $row->calculated_at : null;
            if ($current !== null && ($calculatedAt === null || $current > $calculatedAt)) {
                $calculatedAt = $current;
            }
        }

        return [
            'net_exposure_usdt' => $this->formatAmount($net),
            'target_hedge_usdt' => $this->formatAmount($target),
            'actual_hedge_usdt' => $this->formatAmount($actual),
            'items' => $rows->count(),
            'calculated_at' => $calculatedAt,
        ];
    }

    private function formatAmount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') ?: '0';
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
