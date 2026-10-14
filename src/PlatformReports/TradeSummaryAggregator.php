<?php
declare(strict_types=1);
namespace SixMm\Shared\PlatformReports;

use InvalidArgumentException;

final class TradeSummaryAggregator
{
    public const AMOUNTS = ['contract_trade_amount', 'prediction_valid_stake_amount'];
    public const ACTIONS = ['contract_trade_count', 'prediction_valid_order_count'];
    public const USERS = ['total_trading_user_count', 'trading_user_count', 'prediction_trading_user_count'];

    public function build(string $date, array $contracts, array $predictions, array $directory, bool $completed): array
    {
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        $groups = [];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source)] = $this->emptyRow($date, $source);
        }
        foreach ($contracts as $contract) {
            $key = $this->key($contract);
            if (!isset($groups[$key])) continue;
            $row = &$groups[$key];
            $users = is_string($contract['contract_users']) ? json_decode($contract['contract_users'], true, 512, JSON_THROW_ON_ERROR) : $contract['contract_users'];
            if (!is_array($users) || count($users) !== (int) $contract['trading_user_count']) throw new InvalidArgumentException('合约日报缺少交易用户去重明细');
            foreach ($users as $id => $_) {
                if (!preg_match('/^[1-9]\d*$/D', (string) $id)) throw new InvalidArgumentException('合约交易用户ID无效');
                $row['identities']['contract_users']['u:' . $id] = true;
            }
            $row['contract_trade_count'] = $this->count($contract['fill_count']);
            $row['contract_trade_amount'] = $this->decimal($contract['trade_amount']);
            $row['status'] = (int) $contract['status'] === 2 ? 2 : ($completed && (int) $contract['status'] === 1 ? 1 : 0);
            unset($row);
        }
        foreach ($predictions as $fact) {
            if (!in_array($fact['outcome'] ?? '', ['win', 'loss'], true)) continue;
            $id = (string) ($fact['user_id'] ?? '');
            $user = $directory['users'][$id] ?? null;
            if ($user === null || !in_array((int) ($user['user_type'] ?? 0), [1, 2], true)) continue;
            $count = $this->count($fact['order_count'] ?? 0);
            if ($count === 0) continue;
            $amount = $this->decimal($fact['stake_amount'] ?? '0');
            $tenantId = (string) ($directory['sites'][$user['site_id']] ?? '');
            $tenant = $directory['tenants'][$tenantId] ?? null;
            $agentId = (string) ($directory['roots'][$user['agent_id']] ?? $user['agent_id']);
            $merchant = $directory['agents'][$agentId] ?? null;
            if ($merchant !== null && $merchant['site_id'] !== $user['site_id']) $merchant = null;
            $partial = $tenant === null || ($user['agent_id'] !== '0' && $merchant === null);
            foreach (array_filter([$merchant, $tenant, $platform]) as $source) {
                $row = &$groups[$this->key($source)];
                $row['identities']['prediction_users']['u:' . $id] = true;
                $row['prediction_valid_order_count'] += $count;
                $row['prediction_valid_stake_amount'] = bcadd($row['prediction_valid_stake_amount'], $amount, 16);
                if ($partial) $row['status'] = 2;
                unset($row);
            }
        }
        return array_values(array_map([$this, 'finish'], $groups));
    }

    public function emptyRow(string $date, array $source): array
    {
        return ['stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'], 'status' => 2,
            'contract_trade_count' => 0, 'prediction_valid_order_count' => 0, 'contract_trade_amount' => '0',
            'prediction_valid_stake_amount' => '0', 'identities' => ['contract_users' => [], 'prediction_users' => []]];
    }

    public function merge(array &$into, array $row): void
    {
        foreach (self::ACTIONS as $field) $into[$field] += (int) $row[$field];
        foreach (self::AMOUNTS as $field) $into[$field] = bcadd($into[$field], (string) $row[$field], 16);
        $ids = is_string($row['identities']) ? json_decode($row['identities'], true, 512, JSON_THROW_ON_ERROR) : $row['identities'];
        foreach (['contract_users', 'prediction_users'] as $type) {
            foreach ($ids[$type] as $id => $_) $into['identities'][$type][$id] = true;
        }
        if ((int) $row['status'] === 2 || $into['status'] === 2) $into['status'] = 2;
        elseif ((int) $row['status'] === 0 || $into['status'] === 0) $into['status'] = 0;
        if (strcmp((string) ($row['updated_at'] ?? ''), (string) ($into['updated_at'] ?? '')) > 0) $into['updated_at'] = $row['updated_at'];
    }

    public function finish(array $row): array
    {
        $row['trading_user_count'] = count($row['identities']['contract_users']);
        $row['prediction_trading_user_count'] = count($row['identities']['prediction_users']);
        $row['total_trading_user_count'] = count($row['identities']['contract_users'] + $row['identities']['prediction_users']);
        foreach (self::AMOUNTS as $field) $row[$field] = bcadd($row[$field], '0', 16);
        return $row;
    }

    private function key(array $row): string { return $row['source_type'] . ':' . $row['source_id']; }
    private function count(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D', (string) $value)
            || bccomp((string) $value, (string) PHP_INT_MAX, 0) > 0) throw new InvalidArgumentException('交易笔数格式无效');
        return (int) $value;
    }
    private function decimal(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d+(?:\.\d+)?$/D', $value)) throw new InvalidArgumentException('交易金额必须为非负十进制字符串');
        // Prediction NUMERIC commonly returns 18 fixed places. Extra zeroes are
        // insignificant; never silently truncate significant digits beyond the report scale.
        $fraction = explode('.', $value, 2)[1] ?? '';
        if (strlen(rtrim($fraction, '0')) > 16) throw new InvalidArgumentException('交易金额有效小数位超过报表的16位精度');
        return bcadd($value, '0', 16);
    }
}
