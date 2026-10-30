<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

final class FundingFeeReportAggregator
{
    public const AMOUNTS = [
        'funding_settlement_position_amount', 'funding_fee_income',
        'funding_fee_expense', 'funding_fee_net_income',
    ];

    /** @param iterable<array<string, mixed>> $facts */
    public function build(string $date, iterable $facts, array $directory, bool $completed): array
    {
        $scope = new ReportUserScope($directory);
        $groups = [];
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source, '')] = $this->emptyRow($date, $source, '', $completed);
        }

        foreach ($facts as $fact) {
            if (strtoupper((string) ($fact['settle_asset'] ?? '')) !== 'USDT') continue;
            $user = $scope->user($fact);
            if ($user === null) continue;
            $merchant = $scope->merchant($fact);
            $tenantId = $directory['sites'][$user['site_id']] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $complete = $tenant !== null && ($merchant !== null || !ReportUserScope::positiveId((string) $user['agent_id']));
            foreach (array_filter([$merchant, $tenant, $platform]) as $source) {
                foreach (array_unique(['', strtoupper((string) ($fact['symbol'] ?? ''))]) as $symbol) {
                    $key = $this->key($source, $symbol);
                    $groups[$key] ??= $this->emptyRow($date, $source, $symbol, $completed);
                    $row = &$groups[$key];
                    $count = max(0, (int) ($fact['funding_settlement_count'] ?? 0));
                    $row['funding_settlement_count'] += $count;
                    if ($count > 0) $row['user_ids'][(string) $fact['user_id']] = true;
                    foreach (self::AMOUNTS as $field) {
                        $value = $field === 'funding_fee_net_income' ? ($fact['funding_fee'] ?? '0') : ($fact[$field] ?? '0');
                        $row[$field] = bcadd($row[$field], (string) $value, 16);
                    }
                    if (!$complete) $row['status'] = 2;
                    unset($row);
                }
            }
            if (!$complete) {
                foreach ($groups as &$group) if ($group['source_type'] === 'tenant') $group['status'] = 2;
                unset($group);
            }
        }
        foreach ($groups as &$group) {
            $group['funding_settlement_user_count'] = count($group['user_ids']);
            $group['user_ids'] = array_map('strval', array_keys($group['user_ids']));
        }
        unset($group);
        return array_values($groups);
    }

    private function emptyRow(string $date, array $source, string $symbol, bool $completed): array
    {
        return [
            'stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'], 'symbol' => $symbol,
            'funding_settlement_count' => 0, 'funding_settlement_position_amount' => '0',
            'funding_fee_income' => '0', 'funding_fee_expense' => '0', 'funding_fee_net_income' => '0',
            'user_ids' => [], 'status' => $completed ? 1 : 0,
        ];
    }

    private function key(array $source, string $symbol): string
    {
        return $source['source_type'] . ':' . $source['source_id'] . ':' . $symbol;
    }
}
