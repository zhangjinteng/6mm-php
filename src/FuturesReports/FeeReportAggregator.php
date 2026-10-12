<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

final class FeeReportAggregator
{
    public const COUNTS = ['fee_user_count', 'fee_count'];
    public const AMOUNTS = ['maker_trade_amount', 'taker_trade_amount', 'maker_handling_fee', 'taker_handling_fee', 'total_handling_fee'];
    private const FLOWS = ['maker_trade_amount', 'taker_trade_amount', 'maker_handling_fee', 'taker_handling_fee'];

    /** Preserve the existing commission page's simulated-user USDT handling-fee population. */
    public function build(string $date, iterable $facts, array $directory, bool $completed): array
    {
        $scope = new ReportUserScope($directory);
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        $groups = [];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source, '')] = $this->emptyRow($date, $source, '', $completed);
        }
        foreach ($facts as $fact) {
            if (!is_array($fact) || (int) ($fact['user_type'] ?? 0) !== 2 || strtoupper((string) ($fact['settle_asset'] ?? '')) !== 'USDT') continue;
            $user = $scope->user($fact);
            if ($user === null) continue;
            $count = $this->count($fact['fee_count'] ?? 0);
            $flows = [];
            foreach (self::FLOWS as $field) $flows[$field] = $this->amount($fact[$field] ?? '0');
            if ($count === 0 && !array_filter($flows, static fn (string $value): bool => bccomp($value, '0', 16) !== 0)) continue;
            $merchant = $scope->merchant($fact);
            $tenantId = $directory['sites'][$user['site_id']] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $complete = $tenant !== null && ($merchant !== null || ((string) ($fact['agent_id'] ?? '') === '0' && $user['agent_id'] === '0'));
            $symbol = strtoupper((string) ($fact['symbol'] ?? ''));
            foreach (array_filter([$merchant, $tenant, $platform]) as $source) {
                foreach (array_unique(['', $symbol]) as $contract) {
                    $key = $this->key($source, $contract);
                    $groups[$key] ??= $this->emptyRow($date, $source, $contract, $completed);
                    $groups[$key]['fee_count'] += $count;
                    if ($count > 0) $groups[$key]['identities']['users'][(string) $fact['user_id']] = true;
                    foreach ($flows as $field => $amount) $groups[$key][$field] = bcadd($groups[$key][$field], $amount, 16);
                    if (!$complete) $groups[$key]['status'] = 2;
                }
            }
        }
        return array_values(array_map([$this, 'finish'], $groups));
    }

    public function mergeDaily(array $rows, string $month): array
    {
        if ($rows === []) throw new InvalidArgumentException('手续费月报缺少日报');
        $result = $this->emptyRow($month, $rows[0], (string) $rows[0]['symbol'], true);
        foreach ($rows as $row) {
            $result['fee_count'] += $this->count($row['fee_count']);
            foreach (self::FLOWS as $field) $result[$field] = bcadd($result[$field], $this->amount($row[$field]), 16);
            $ids = is_string($row['identities']) ? json_decode($row['identities'], true, 512, JSON_THROW_ON_ERROR) : $row['identities'];
            foreach ($ids['users'] ?? [] as $id => $value) $result['identities']['users'][(string) $id] = true;
            if ((int) $row['status'] === 2) $result['status'] = 2;
            elseif ((int) $row['status'] === 0 && $result['status'] !== 2) $result['status'] = 0;
            if (isset($row['updated_at']) && (!isset($result['updated_at']) || $row['updated_at'] > $result['updated_at'])) $result['updated_at'] = $row['updated_at'];
        }
        return $this->finish($result);
    }

    private function finish(array $row): array
    {
        $row['fee_user_count'] = count($row['identities']['users']);
        $row['total_handling_fee'] = bcadd($row['maker_handling_fee'], $row['taker_handling_fee'], 16);
        return $row;
    }

    private function emptyRow(string $date, array $source, string $symbol, bool $completed): array
    {
        $row = ['stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'], 'symbol' => $symbol,
            'identities' => ['users' => []], 'status' => $completed ? 1 : 0];
        foreach (self::COUNTS as $field) $row[$field] = 0;
        foreach (self::AMOUNTS as $field) $row[$field] = '0.0000000000000000';
        return $row;
    }

    private function amount(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d+(\.\d{1,16})?$/D', (string) $value)) throw new InvalidArgumentException('手续费统计金额必须为非负十进制字符串');
        return (string) $value;
    }

    private function count(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D', (string) $value) || bccomp((string) $value, (string) PHP_INT_MAX, 0) > 0) throw new InvalidArgumentException('扣费笔数必须为非负整数');
        return (int) $value;
    }

    private function key(array $source, string $symbol): string { return $source['source_type'] . ':' . $source['source_id'] . ':' . $symbol; }
}
