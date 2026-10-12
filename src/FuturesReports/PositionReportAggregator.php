<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

/** Aggregates one instantaneous position snapshot; snapshots must never be added across dates. */
final class PositionReportAggregator
{
    private const AMOUNTS = ['long_position_amount', 'short_position_amount', 'closing_position_amount', 'occupied_margin', 'unrealized_pnl'];

    public function build(string $date, array $snapshot, array $directory, bool $completed): array
    {
        if (($snapshot['status'] ?? null) !== 'completed' || !is_array($snapshot['rows'] ?? null)) {
            throw new InvalidArgumentException('持仓快照缺失或尚未完成');
        }
        $at = (string) ($snapshot['snapshot_at'] ?? '');
        if ($at === '') throw new InvalidArgumentException('持仓快照缺少采集时间');
        $scope = new ReportUserScope($directory);
        $groups = [];
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source, '')] = $this->emptyRow($date, $source, '', $completed, $at);
        }
        $incompleteOrigin = false;
        foreach ($snapshot['rows'] as $fact) {
            $user = $scope->user($fact);
            if ($user === null) {
                $incompleteOrigin = true;
                continue;
            }
            $agent = $scope->merchant($fact);
            $tenantId = $directory['sites'][$user['site_id']] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $complete = $tenant !== null && ($agent !== null || ((string) ($fact['agent_id'] ?? '') === '0' && $user['agent_id'] === '0'));
            if (!$complete) $incompleteOrigin = true;
            foreach (array_filter([$agent, $tenant, $platform]) as $source) {
                foreach (array_unique(['', (string) ($fact['symbol'] ?? '')]) as $symbol) {
                    $key = $this->key($source, $symbol);
                    $groups[$key] ??= $this->emptyRow($date, $source, $symbol, $completed, $at);
                    foreach (self::AMOUNTS as $field) {
                        $value = (string) ($fact[$field] ?? '0');
                        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) throw new InvalidArgumentException('持仓快照金额格式无效');
                        $groups[$key][$field] = bcadd($groups[$key][$field], $value, 16);
                    }
                    $id = (string) $fact['user_id'];
                    if (bccomp((string) ($fact['closing_position_amount'] ?? '0'), '0', 16) > 0) $groups[$key]['users'][$id] = true;
                    if (bccomp((string) ($fact['long_position_amount'] ?? '0'), '0', 16) > 0) $groups[$key]['long_users'][$id] = true;
                    if (bccomp((string) ($fact['short_position_amount'] ?? '0'), '0', 16) > 0) $groups[$key]['short_users'][$id] = true;
                    if (!$complete) $groups[$key]['status'] = 2;
                }
            }
        }
        if ($incompleteOrigin) {
            foreach ($groups as &$group) if ($group['source_type'] !== 'merchant') $group['status'] = 2;
            unset($group);
        }
        return array_values(array_map(static function (array $row): array {
            $row['position_user_count'] = count($row['users']);
            $row['long_position_user_count'] = count($row['long_users']);
            $row['short_position_user_count'] = count($row['short_users']);
            $row['average_leverage'] = bccomp($row['occupied_margin'], '0', 16) > 0
                ? bcdiv($row['closing_position_amount'], $row['occupied_margin'], 8) : '0';
            unset($row['users'], $row['long_users'], $row['short_users']);
            return $row;
        }, $groups));
    }

    private function emptyRow(string $date, array $source, string $symbol, bool $completed, string $at): array
    {
        $row = ['stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'], 'symbol' => $symbol,
            'users' => [], 'long_users' => [], 'short_users' => [], 'status' => $completed ? 1 : 0, 'snapshot_at' => $at];
        foreach (self::AMOUNTS as $field) $row[$field] = '0';
        return $row;
    }

    private function key(array $source, string $symbol): string
    {
        return $source['source_type'] . ':' . $source['source_id'] . ':' . $symbol;
    }
}
