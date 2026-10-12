<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

/** Reuses trade-report origin and notional; classifies PnL only after per-user aggregation. */
final class PnlReportAggregator
{
    public function build(string $date, iterable $facts, array $directory, array $tradeRows): array
    {
        $groups = [];
        foreach ($tradeRows as $trade) {
            if ($trade['stat_date'] !== $date) throw new InvalidArgumentException('交易日报与盈亏日报日期不一致');
            $key = $this->key($trade);
            $groups[$key] = [
                'stat_date' => $date,
                'source_type' => $trade['source_type'],
                'source_id' => (string) $trade['source_id'],
                'source_code' => $trade['source_code'],
                'source_name' => $trade['source_name'],
                'symbol' => $trade['symbol'],
                'trade_amount' => (string) $trade['trade_amount'],
                'status' => (int) $trade['status'],
                'user_metrics' => [],
            ];
        }

        $scope = new ReportUserScope($directory);
        foreach ($facts as $fact) {
            if (!is_array($fact) || strtoupper((string) ($fact['settle_asset'] ?? 'USDT')) !== 'USDT') continue;
            $user = $scope->user($fact);
            if ($user === null) continue;
            $agent = $scope->merchant($fact);
            $tenantId = $directory['sites'][$user['site_id']] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $platform = ['source_type' => 'platform', 'source_id' => '0'];
            $userId = (string) $fact['user_id'];
            $pnl = (string) ($fact['realized_pnl'] ?? '0');
            if (!is_numeric($pnl)) throw new InvalidArgumentException('合约盈亏事实金额无效');
            foreach (array_filter([$agent, $tenant, $platform]) as $source) {
                foreach (array_unique(['', (string) ($fact['symbol'] ?? '')]) as $symbol) {
                    $key = $this->key($source + ['symbol' => $symbol]);
                    if (!isset($groups[$key])) throw new InvalidArgumentException('盈亏事实缺少对应交易日报来源');
                    $metric = $groups[$key]['user_metrics'][$userId] ?? ['pnl' => '0', 'traded' => false];
                    $metric['pnl'] = bcadd($metric['pnl'], $pnl, 16);
                    $metric['traded'] = $metric['traded'] || (int) ($fact['fill_count'] ?? 0) > 0;
                    $groups[$key]['user_metrics'][$userId] = $metric;
                }
            }
        }

        return array_values(array_map([$this, 'finish'], $groups));
    }

    public function mergeDaily(array $rows, string $month): array
    {
        if ($rows === []) throw new InvalidArgumentException('月报缺少日报');
        $result = $rows[0];
        $result['stat_date'] = $month;
        $result['trade_amount'] = '0';
        $result['user_metrics'] = [];
        $result['status'] = 1;
        foreach ($rows as $row) {
            $result['trade_amount'] = bcadd($result['trade_amount'], (string) $row['trade_amount'], 16);
            $metrics = is_string($row['user_metrics'])
                ? json_decode($row['user_metrics'], true, 512, JSON_THROW_ON_ERROR)
                : $row['user_metrics'];
            foreach ($metrics as $userId => $metric) {
                $existing = $result['user_metrics'][(string) $userId] ?? ['pnl' => '0', 'traded' => false];
                $existing['pnl'] = bcadd($existing['pnl'], (string) $metric['pnl'], 16);
                $existing['traded'] = $existing['traded'] || (bool) $metric['traded'];
                $result['user_metrics'][(string) $userId] = $existing;
            }
            if ((int) $row['status'] === 2) $result['status'] = 2;
            elseif ((int) $row['status'] === 0 && $result['status'] !== 2) $result['status'] = 0;
        }
        return $this->finish($result);
    }

    private function finish(array $row): array
    {
        $row['trading_user_count'] = 0;
        $row['profitable_user_count'] = 0;
        $row['losing_user_count'] = 0;
        $row['profit_amount'] = '0';
        $row['loss_amount'] = '0';
        foreach ($row['user_metrics'] as $metric) {
            if ($metric['traded']) $row['trading_user_count']++;
            $sign = bccomp((string) $metric['pnl'], '0', 16);
            if ($sign > 0) {
                $row['profitable_user_count']++;
                $row['profit_amount'] = bcadd($row['profit_amount'], (string) $metric['pnl'], 16);
            } elseif ($sign < 0) {
                $row['losing_user_count']++;
                $row['loss_amount'] = bcadd($row['loss_amount'], bcsub('0', (string) $metric['pnl'], 16), 16);
            }
        }
        $row['net_pnl'] = bcsub($row['profit_amount'], $row['loss_amount'], 16);
        return $row;
    }

    private function key(array $row): string
    {
        return $row['source_type'] . ':' . $row['source_id'] . ':' . $row['symbol'];
    }
}
