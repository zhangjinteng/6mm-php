<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

/** Contract-side platform PnL, grouped with the same origin rules as trade reports. */
final class PlatformPnlReportAggregator
{
    public const AMOUNTS = [
        'contract_trade_pnl', 'contract_trading_fee_income',
        'contract_liquidation_fee_income', 'platform_funding_fee_net', 'contract_total_pnl',
    ];

    public function build(string $date, iterable $facts, array $directory, bool $completed): array
    {
        $groups = [];
        $scope = new ReportUserScope($directory);
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source)] = $this->emptyRow($date, $source, $completed);
        }
        foreach ($facts as $fact) {
            if (!is_array($fact)
                || strtoupper((string) ($fact['settle_asset'] ?? '')) !== 'USDT') continue;
            $user = $scope->user($fact);
            if ($user === null) continue; // Missing/non-positive user sites are outside the cohort.
            $merchant = $scope->merchant($fact);
            $tenantId = $directory['sites'][(string) $user['site_id']] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $complete = $tenant !== null && ($merchant !== null
                || !ReportUserScope::positiveId((string) $user['agent_id']));
            $realized = $this->decimal($fact['realized_pnl'] ?? '0');
            $fee = $this->decimal($fact['trading_fee'] ?? '0');
            $liquidation = $this->decimal($fact['liquidation_fee'] ?? '0');
            $funding = $this->decimal($fact['funding_fee'] ?? '0');
            foreach (array_filter([$merchant, $tenant, $platform]) as $source) {
                $groups[$this->key($source)] ??= $this->emptyRow($date, $source, $completed);
                $row = &$groups[$this->key($source)];
                $row['contract_trade_pnl'] = bcsub($row['contract_trade_pnl'], $realized, 16);
                $row['contract_trading_fee_income'] = bcadd($row['contract_trading_fee_income'], $fee, 16);
                $row['contract_liquidation_fee_income'] = bcadd($row['contract_liquidation_fee_income'], $liquidation, 16);
                $row['platform_funding_fee_net'] = bcadd($row['platform_funding_fee_net'], $funding, 16);
                if (!$complete) $row['status'] = 2;
                unset($row);
            }
            if (!$complete) {
                foreach ($groups as &$row) if ($row['source_type'] === 'tenant') $row['status'] = 2;
                unset($row);
            }
        }
        foreach ($groups as &$row) {
            $row['contract_total_pnl'] = bcadd(
                bcadd($row['contract_trade_pnl'], $row['contract_trading_fee_income'], 16),
                bcadd($row['contract_liquidation_fee_income'], $row['platform_funding_fee_net'], 16), 16);
        }
        unset($row);
        return array_values($groups);
    }

    private function emptyRow(string $date, array $source, bool $completed): array
    {
        $row = ['stat_date' => $date, 'source_type' => $source['source_type'],
            'source_id' => (string) $source['source_id'], 'source_code' => $source['source_code'],
            'source_name' => $source['source_name'], 'settle_asset' => 'USDT',
            'status' => $completed ? 1 : 0];
        foreach (self::AMOUNTS as $field) $row[$field] = '0';
        return $row;
    }

    private function decimal(mixed $value): string
    {
        $text = (string) $value;
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $text)) throw new InvalidArgumentException('平台盈亏事实金额格式无效');
        return $text;
    }

    private function key(array $source): string
    {
        return $source['source_type'] . ':' . $source['source_id'];
    }
}
