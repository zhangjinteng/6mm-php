<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

final class RiskReportAggregator
{
    public const COUNTS = ['liquidation_user_count', 'liquidation_count', 'bankruptcy_count'];
    public const AMOUNTS = ['liquidation_position_amount', 'liquidation_trade_amount', 'liquidation_realized_pnl',
        'liquidation_fee', 'bankruptcy_loss', 'uncollected_liquidation_fee', 'uncollected_trading_fee'];
    private const FLOWS = ['liquidation_trade_amount', 'liquidation_realized_pnl', 'liquidation_fee',
        'uncollected_liquidation_fee', 'uncollected_trading_fee'];

    /** Same population as the existing agent risk report: simulated USDT users only. */
    public function build(string $date, iterable $facts, array $directory, bool $completed): array
    {
        $scope = new ReportUserScope($directory);
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        $groups = [];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source, '')] = $this->emptyRow($date, $source, '', $completed);
        }
        foreach ($facts as $fact) {
            if (!is_array($fact) || (int) ($fact['user_type'] ?? 0) !== 2
                || strtoupper((string) ($fact['settle_asset'] ?? 'USDT')) !== 'USDT') continue;
            $user = $scope->user($fact);
            if ($user === null) continue;
            $ids = ['users' => [], 'positions' => [], 'bankruptcies' => []];
            $symbol = strtoupper((string) ($fact['symbol'] ?? ''));
            $prefix = (string) $fact['user_id'] . ':' . $symbol . ':';
            foreach (($fact['liquidation_position_ids'] ?? []) as $id) {
                $id = (string) $id;
                if (!ReportUserScope::positiveId($id)) throw new InvalidArgumentException('强平仓位ID无效');
                if (!array_key_exists($id, $fact['liquidation_position_amounts'] ?? [])) throw new InvalidArgumentException('强平小时桶缺少仓位金额去重明细');
                $amount = $this->amount($fact['liquidation_position_amounts'][$id]);
                if (bccomp($amount, '0', 16) < 0) throw new InvalidArgumentException('强平持仓金额不能为负数');
                $ids['positions'][$prefix . $id] = $amount;
            }
            if ($ids['positions'] !== []) $ids['users'][(string) $fact['user_id']] = true;
            foreach (($fact['bankruptcy_event_amounts'] ?? []) as $id => $amount) {
                if (trim((string) $id) === '') throw new InvalidArgumentException('穿仓账本事件ID无效');
                $amount = $this->amount($amount);
                if (bccomp($amount, '0', 16) < 0) throw new InvalidArgumentException('穿仓损失不能为负数');
                if (bccomp($amount, '0', 16) > 0) $ids['bankruptcies'][$prefix . $id] = $amount;
            }
            if (bccomp($this->amount($fact['bankruptcy_loss'] ?? '0'), '0', 16) > 0 && $ids['bankruptcies'] === []) {
                throw new InvalidArgumentException('强平小时桶缺少穿仓账本去重明细');
            }
            $flows = [];
            foreach (self::FLOWS as $field) $flows[$field] = $this->amount($fact[$field] ?? '0');
            if (bccomp($flows['liquidation_trade_amount'], '0', 16) > 0 && $ids['positions'] === []) {
                throw new InvalidArgumentException('强平小时桶缺少仓位去重明细');
            }
            if ($ids['positions'] === [] && $ids['bankruptcies'] === []
                && !array_filter($flows, static fn (string $amount): bool => bccomp($amount, '0', 16) !== 0)) continue;
            $merchant = $scope->merchant($fact);
            $tenantId = $directory['sites'][$user['site_id']] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $complete = $tenant !== null && ($merchant !== null || ((string) ($fact['agent_id'] ?? '') === '0' && $user['agent_id'] === '0'));
            foreach (array_filter([$merchant, $tenant, $platform]) as $source) {
                foreach (array_unique(['', $symbol]) as $contract) {
                    $key = $this->key($source, $contract);
                    $groups[$key] ??= $this->emptyRow($date, $source, $contract, $completed);
                    foreach ($flows as $field => $amount) $groups[$key][$field] = bcadd($groups[$key][$field], $amount, 16);
                    $this->mergeIdentities($groups[$key]['identities'], $ids);
                    if (!$complete) $groups[$key]['status'] = 2;
                }
            }
        }
        return array_values(array_map([$this, 'finish'], $groups));
    }

    public function mergeDaily(array $rows, string $month): array
    {
        if ($rows === []) throw new InvalidArgumentException('强平月报缺少日报');
        $result = $this->emptyRow($month, $rows[0], (string) $rows[0]['symbol'], true);
        foreach ($rows as $row) {
            foreach (self::FLOWS as $field) $result[$field] = bcadd($result[$field], $this->amount($row[$field]), 16);
            $ids = is_string($row['identities']) ? json_decode($row['identities'], true, 512, JSON_THROW_ON_ERROR) : $row['identities'];
            $this->mergeIdentities($result['identities'], $ids);
            if ((int) $row['status'] === 2) $result['status'] = 2;
            elseif ((int) $row['status'] === 0 && $result['status'] !== 2) $result['status'] = 0;
            if (isset($row['updated_at']) && (!isset($result['updated_at']) || $row['updated_at'] > $result['updated_at'])) $result['updated_at'] = $row['updated_at'];
        }
        return $this->finish($result);
    }

    private function mergeIdentities(array &$into, array $ids): void
    {
        foreach (($ids['users'] ?? []) as $id => $value) $into['users'][(string) $id] = true;
        foreach (['positions', 'bankruptcies'] as $type) {
            foreach (($ids[$type] ?? []) as $id => $amount) {
                $amount = $this->amount($amount);
                if (!isset($into[$type][$id]) || bccomp($amount, $into[$type][$id], 16) > 0) $into[$type][$id] = $amount;
            }
        }
    }

    private function finish(array $row): array
    {
        $row['liquidation_user_count'] = count($row['identities']['users']);
        $row['liquidation_count'] = count($row['identities']['positions']);
        $row['bankruptcy_count'] = count($row['identities']['bankruptcies']);
        foreach (['positions' => 'liquidation_position_amount', 'bankruptcies' => 'bankruptcy_loss'] as $type => $field) {
            $row[$field] = '0.0000000000000000';
            foreach ($row['identities'][$type] as $amount) $row[$field] = bcadd($row[$field], $amount, 16);
        }
        return $row;
    }

    private function emptyRow(string $date, array $source, string $symbol, bool $completed): array
    {
        $row = ['stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'], 'symbol' => $symbol,
            'identities' => ['users' => [], 'positions' => [], 'bankruptcies' => []], 'status' => $completed ? 1 : 0];
        foreach (self::COUNTS as $field) $row[$field] = 0;
        foreach (self::AMOUNTS as $field) $row[$field] = '0.0000000000000000';
        return $row;
    }

    private function amount(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('强平统计金额必须为十进制字符串');
        $value = (string) $value;
        if (!preg_match('/^-?\d+(\.\d{1,16})?$/D', $value)) throw new InvalidArgumentException('强平统计金额必须为十进制字符串');
        return $value;
    }

    private function key(array $source, string $symbol): string { return $source['source_type'] . ':' . $source['source_id'] . ':' . $symbol; }
}
