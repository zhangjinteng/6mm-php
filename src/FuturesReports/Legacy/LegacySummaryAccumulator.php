<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports\Legacy;

use SixMm\Shared\FuturesReports\ReportUserScope;
use SixMm\Shared\FuturesReports\Legacy\Models\User;

/** Retain compact day-level identities and metrics, never decoded hourly payloads. */
final class LegacySummaryAccumulator
{
    private array $executions = [];
    private array $settlements = [];
    private array $fees = [];

    public function merge(array $settlements, array $trades): void
    {
        ReportUserScope::mergeExecutions($this->executions, $trades);
        foreach ($settlements as $row) {
            if ((int) ($row['user_type'] ?? 0) === User::USER_TYPE_NORMAL) continue;
            $key = $this->key($row);
            $this->settlements[$key] ??= $this->identity($row) + [
                'close_order_ids' => [], 'close_order_count' => 0,
                'close_amount' => '0', 'realized_pnl' => '0', 'trading_fee' => '0',
                'funding_fee' => '0', 'liquidation_fee' => '0',
            ];
            $metric = &$this->settlements[$key];
            $ids = $row['close_order_ids'] ?? [];
            if (is_string($ids)) $ids = json_decode($ids, true) ?? [];
            $hasIds = false;
            foreach (is_array($ids) ? $ids : [] as $id) {
                $id = trim((string) $id);
                if ($id !== '' && $id !== '0') {
                    $metric['close_order_ids'][$id] = true;
                    $hasIds = true;
                }
            }
            // Matches the existing service: explicit IDs override legacy count-only rows.
            $metric['close_order_count'] = $hasIds ? count($metric['close_order_ids'])
                : $metric['close_order_count'] + max(0, (int) ($row['close_order_count'] ?? 0));
            foreach (['close_amount', 'realized_pnl', 'trading_fee', 'funding_fee', 'liquidation_fee'] as $field) {
                $metric[$field] = $this->add($metric[$field], $row[$field] ?? '0');
            }
            unset($metric);
        }
        foreach ($trades as $row) {
            // Only these fees are consumed by the simulated user PnL service.
            if ((int) ($row['user_type'] ?? 0) !== User::USER_TYPE_SIMULATE
                || strtoupper((string) ($row['settle_asset'] ?? '')) !== FuturesDailySourceProvider::SETTLE_ASSET) continue;
            $key = $this->key($row);
            $this->fees[$key] ??= $this->identity($row) + ['trading_fee' => '0'];
            $this->fees[$key]['trading_fee'] = $this->add($this->fees[$key]['trading_fee'], $row['trading_fee'] ?? '0');
        }
    }

    public function executionRows(): array
    {
        return ReportUserScope::finishExecutions($this->executions);
    }

    public function settlementRows(): array
    {
        return array_values(array_map(static function (array $row): array {
            $row['close_order_ids'] = array_map('strval', array_keys($row['close_order_ids']));
            return $row;
        }, $this->settlements));
    }

    public function feeRows(): array
    {
        return array_values($this->fees);
    }

    private function identity(array $row): array
    {
        return [
            'agent_id' => $row['agent_id'], 'user_type' => $row['user_type'],
            'user_id' => $row['user_id'], 'settle_asset' => strtoupper((string) ($row['settle_asset'] ?? 'USDT')),
        ];
    }

    private function key(array $row): string
    {
        return implode(':', [$row['agent_id'], $row['user_type'], $row['user_id']]);
    }

    private function add(string $left, mixed $right): string
    {
        return bcadd($left, is_numeric($right) ? (string) $right : '0', 16);
    }
}
