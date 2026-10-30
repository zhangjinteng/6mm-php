<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

/** Aggregates one UTC day from the same two fact streams as the Agent user-PnL report. */
final class UserNetPnlReportAggregator
{
    public const COUNTS = ['settled_user_count', 'profitable_user_count', 'losing_user_count', 'breakeven_user_count', 'close_order_count'];
    public const AMOUNTS = ['close_amount', 'realized_pnl', 'trading_fee', 'funding_fee', 'liquidation_fee', 'true_net_pnl'];

    /** @param iterable<array{kind: string, row: array}> $facts */
    public function build(string $date, iterable $facts, array $directory, bool $completed): array
    {
        $groups = [];
        $scope = new ReportUserScope($directory);
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source)] = $this->emptyRow($date, $source, $completed);
        }

        foreach ($facts as $item) {
            if (!in_array($item['kind'] ?? null, ['settlement', 'trade'], true) || !is_array($item['row'] ?? null)) {
                throw new InvalidArgumentException('用户净盈亏小时事实格式无效');
            }
            $fact = $item['row'];
            if (strtoupper((string) ($fact['settle_asset'] ?? '')) !== 'USDT') continue;
            $userId = (string) ($fact['user_id'] ?? '');
            if (!ReportUserScope::positiveId($userId)) throw new InvalidArgumentException('用户净盈亏事实缺少有效用户ID');
            $user = $scope->user($fact);
            if ($user === null) continue;
            $merchant = $scope->merchant($fact);
            $tenantId = $directory['sites'][(string) $user['site_id']] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $complete = $tenant !== null && ($merchant !== null || !ReportUserScope::positiveId((string) $user['agent_id']));
            foreach (array_filter([$merchant, $tenant, $platform]) as $source) {
                $groups[$this->key($source)] ??= $this->emptyRow($date, $source, $completed);
                $group = &$groups[$this->key($source)];
                $group['users'][$userId] ??= $this->emptyUser();
                $metric = &$group['users'][$userId];
                if ($item['kind'] === 'trade') {
                    $fee = $this->decimal($fact['trading_fee'] ?? '0');
                    $metric['trading_fee'] = bcadd($metric['trading_fee'], $fee, 16);
                    $group['trading_fee'] = bcadd($group['trading_fee'], $fee, 16);
                } else {
                    $metric['settled'] = true;
                    $ids = $fact['close_order_ids'] ?? [];
                    if (!is_array($ids)) throw new InvalidArgumentException('平仓委托ID格式无效');
                    $count = max(0, (int) ($fact['close_order_count'] ?? 0));
                    if ($count > 0 && $ids === []) throw new InvalidArgumentException('小时桶缺少平仓委托ID');
                    foreach ($ids as $id) {
                        $id = trim((string) $id);
                        if ($id === '' || $id === '0') throw new InvalidArgumentException('平仓委托ID格式无效');
                        $metric['close_ids'][$id] = true;
                    }
                    foreach (['close_amount', 'realized_pnl', 'funding_fee', 'liquidation_fee'] as $field) {
                        $value = $this->decimal($fact[$field] ?? '0');
                        $metric[$field] = bcadd($metric[$field], $value, 16);
                        $group[$field] = bcadd($group[$field], $value, 16);
                    }
                }
                if (!$complete) $group['status'] = 2;
                unset($metric, $group);
            }
            if (!$complete) {
                foreach ($groups as &$group) if ($group['source_type'] === 'tenant') $group['status'] = 2;
                unset($group);
            }
        }

        foreach ($groups as &$group) {
            foreach ($group['users'] as $metric) {
                if (!$metric['settled']) continue;
                $group['settled_user_count']++;
                $group['close_order_count'] += count($metric['close_ids']);
                $net = bcsub(bcsub(bcsub($metric['realized_pnl'], $metric['trading_fee'], 16), $metric['funding_fee'], 16), $metric['liquidation_fee'], 16);
                $classification = bccomp($net, '0', 16);
                $group[$classification > 0 ? 'profitable_user_count' : ($classification < 0 ? 'losing_user_count' : 'breakeven_user_count')]++;
            }
            $group['true_net_pnl'] = bcsub(bcsub(bcsub($group['realized_pnl'], $group['trading_fee'], 16), $group['funding_fee'], 16), $group['liquidation_fee'], 16);
            unset($group['users']);
        }
        unset($group);
        return array_values($groups);
    }

    private function emptyRow(string $date, array $source, bool $completed): array
    {
        $row = [
            'stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'],
            'settle_asset' => 'USDT', 'status' => $completed ? 1 : 0, 'users' => [],
        ];
        foreach (self::COUNTS as $field) $row[$field] = 0;
        foreach (self::AMOUNTS as $field) $row[$field] = '0';
        return $row;
    }

    private function emptyUser(): array
    {
        return ['settled' => false, 'close_ids' => [], 'close_amount' => '0', 'realized_pnl' => '0',
            'trading_fee' => '0', 'funding_fee' => '0', 'liquidation_fee' => '0'];
    }

    private function decimal(mixed $value): string
    {
        $value = (string) $value;
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) throw new InvalidArgumentException('用户净盈亏金额格式无效');
        return $value;
    }

    private function key(array $source): string
    {
        return $source['source_type'] . ':' . $source['source_id'];
    }
}
