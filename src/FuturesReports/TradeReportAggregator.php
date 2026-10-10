<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

final class TradeReportAggregator
{
    public const COUNTS = ['order_count', 'cancel_order_count'];
    public const AMOUNTS = ['order_amount', 'cancel_amount'];

    public function build(string $date, iterable $facts, array $directory, bool $completed): array
    {
        $groups = [];
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source, '')] = $this->emptyRow($date, $source, '', $completed);
        }
        foreach ($facts as $fact) {
            $agent = $directory['agents'][(string) $fact['agent_id']] ?? null;
            $userSite = $directory['users'][(string) $fact['user_id']] ?? '';
            $agentSite = $agent['site_id'] ?? '';
            $site = $userSite !== '' ? $userSite : $agentSite;
            $tenantId = $directory['sites'][$site] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $complete = $tenant !== null && !($userSite !== '' && $agentSite !== '' && $userSite !== $agentSite);
            $sources = array_filter([$agent, $tenant, $platform]);
            foreach ($sources as $source) {
                foreach (array_unique(['', (string) $fact['symbol']]) as $symbol) {
                    $key = $this->key($source, $symbol);
                    $groups[$key] ??= $this->emptyRow($date, $source, $symbol, $completed);
                    $this->mergeFact($groups[$key], $fact);
                    if (!$complete) $groups[$key]['status'] = 2;
                }
            }
            if (!$complete) {
                // An unknown site cannot make any tenant look complete; retain merchant/platform totals.
                foreach ($groups as &$group) if ($group['source_type'] === 'tenant') $group['status'] = 2;
                unset($group);
            }
        }
        return array_values(array_map([$this, 'finish'], $groups));
    }

    public function mergeDaily(array $rows, string $month): array
    {
        $first = $rows[0];
        $result = $this->emptyRow($month, $first, $first['symbol'], true);
        foreach ($rows as $row) {
            foreach (self::COUNTS as $field) $result[$field] += (int) $row[$field];
            foreach (self::AMOUNTS as $field) $result[$field] = bcadd($result[$field], (string) $row[$field], 16);
            $ids = is_string($row['identities']) ? json_decode($row['identities'], true, 512, JSON_THROW_ON_ERROR) : $row['identities'];
            $this->mergeIdentities($result['identities'], $ids);
            if ((int) $row['status'] === 2) $result['status'] = 2;
            elseif ((int) $row['status'] === 0 && $result['status'] !== 2) $result['status'] = 0;
        }
        return $this->finish($result);
    }

    private function mergeFact(array &$row, array $fact): void
    {
        foreach (self::COUNTS as $field) $row[$field] += (int) ($fact[$field] ?? 0);
        foreach (self::AMOUNTS as $field) $row[$field] = bcadd($row[$field], (string) ($fact[$field] ?? '0'), 16);
        $ids = ['users' => [], 'orders' => [], 'executions' => []];
        if ((int) ($fact['fill_count'] ?? 0) > 0) $ids['users'][(string) $fact['user_id']] = true;
        foreach (($fact['filled_order_ids'] ?? []) as $id) $ids['orders'][(string) $id] = true;
        foreach (($fact['execution_details'] ?? []) as $detail) {
            if (!is_array($detail) || count($detail) !== 2 || !preg_match('/^-?\d+$/D', (string) $detail[0]) || !preg_match('/^\d+(\.\d+)?$/D', (string) $detail[1])) {
                throw new InvalidArgumentException('成交去重明细无效');
            }
            $key = $fact['symbol'] . ':' . $detail[0];
            $amount = (string) $detail[1];
            if (!isset($ids['executions'][$key]) || bccomp($amount, $ids['executions'][$key], 16) > 0) $ids['executions'][$key] = $amount;
        }
        if ((int) ($fact['fill_count'] ?? 0) > 0 && $ids['executions'] === []) {
            throw new InvalidArgumentException('小时桶缺少成交ID和金额，不能用旧版本桶生成平台报表');
        }
        $this->mergeIdentities($row['identities'], $ids);
    }

    private function mergeIdentities(array &$into, array $ids): void
    {
        foreach (['users', 'orders'] as $type) foreach ($ids[$type] as $id => $value) $into[$type][(string) $id] = true;
        foreach ($ids['executions'] as $id => $amount) {
            if (!isset($into['executions'][$id]) || bccomp((string) $amount, $into['executions'][$id], 16) > 0) $into['executions'][$id] = (string) $amount;
        }
    }

    private function emptyRow(string $date, array $source, string $symbol, bool $completed): array
    {
        return [
            'stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'], 'symbol' => $symbol,
            'order_count' => 0, 'cancel_order_count' => 0, 'order_amount' => '0', 'cancel_amount' => '0',
            'identities' => ['users' => [], 'orders' => [], 'executions' => []], 'status' => $completed ? 1 : 0,
        ];
    }

    private function finish(array $row): array
    {
        $row['trading_user_count'] = count($row['identities']['users']);
        $row['filled_order_count'] = count($row['identities']['orders']);
        $row['fill_count'] = count($row['identities']['executions']);
        $row['trade_amount'] = '0';
        foreach ($row['identities']['executions'] as $amount) $row['trade_amount'] = bcadd($row['trade_amount'], $amount, 16);
        return $row;
    }

    private function key(array $source, string $symbol): string
    {
        return $source['source_type'] . ':' . $source['source_id'] . ':' . $symbol;
    }
}
