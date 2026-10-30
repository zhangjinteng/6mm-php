<?php

declare(strict_types=1);

namespace SixMm\Shared\PredictionReports;

use InvalidArgumentException;
use SixMm\Shared\FuturesReports\ReportUserScope;

/** Classify each user's net result before rolling it into a source/date/play/symbol bucket. */
final class PredictionUserPnlAggregator
{
    public function build(string $date, array $facts, array $directory, array $roots, array $robotIds, bool $completed): array
    {
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        $groups = [];
        foreach (array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]) as $source) {
            $groups[$this->key($source, 'all', '*')] = $this->emptyRow($date, $source, 'all', '*', $completed);
        }
        $incompletePlatform = false;
        foreach ($facts as $fact) {
            $id = (string) ($fact['user_id'] ?? '');
            if (!ReportUserScope::positiveId($id)) continue;
            $play = strtolower((string) ($fact['play_type'] ?? ''));
            $symbol = strtoupper(trim((string) ($fact['symbol'] ?? '')));
            $outcome = strtolower((string) ($fact['outcome'] ?? ''));
            $orders = (int) ($fact['order_count'] ?? 0);
            if (!in_array($play, ['grid', 'high_low', 'up_down'], true) || $symbol === ''
                || !in_array($outcome, ['win', 'loss', 'refund'], true) || $orders < 1) continue;
            if (strlen($symbol) > 40) throw new InvalidArgumentException('预测合约编码过长');
            $user = $directory['users'][$id] ?? null;
            if (!is_array($user) || !ReportUserScope::positiveId((string) ($user['site_id'] ?? ''))) {
                continue;
            }
            $siteId = (string) $user['site_id'];
            $tenantId = $directory['sites'][$siteId] ?? null;
            $tenant = $directory['tenants'][(string) $tenantId] ?? null;
            $merchant = (new ReportUserScope($directory))->merchant($fact);
            if (ReportUserScope::positiveId((string) ($user['agent_id'] ?? '0')) && $merchant === null) $incompletePlatform = true;
            if ($tenant === null) $incompletePlatform = true;
            $stake = $this->decimal($fact['stake_amount'] ?? '0');
            $returned = $this->decimal($fact['return_amount'] ?? '0');
            foreach (array_filter([$merchant, $tenant, $platform]) as $source) {
                foreach ([['all', '*'], [$play, '*'], ['all', $symbol], [$play, $symbol]] as [$playScope, $symbolScope]) {
                    $key = $this->key($source, $playScope, $symbolScope);
                    $groups[$key] ??= $this->emptyRow($date, $source, $playScope, $symbolScope, $completed);
                    $userTotals = &$groups[$key]['users'][$id];
                    $userTotals ??= ['win' => 0, 'loss' => 0, 'refund' => 0, 'stake' => '0', 'valid_stake' => '0', 'return' => '0'];
                    $userTotals[$outcome] += $orders;
                    $userTotals['stake'] = bcadd($userTotals['stake'], $stake, 16);
                    if ($outcome !== 'refund') $userTotals['valid_stake'] = bcadd($userTotals['valid_stake'], $stake, 16);
                    $userTotals['return'] = bcadd($userTotals['return'], $returned, 16);
                    unset($userTotals);
                    if ($tenant === null || ((string) ($user['agent_id'] ?? '') !== '0' && $merchant === null)) {
                        $groups[$key]['status'] = 2;
                    }
                }
            }
        }
        if ($incompletePlatform) {
            foreach ($groups as &$group) if ($group['source_type'] === 'platform') $group['status'] = 2;
            unset($group);
        }
        $rows = [];
        foreach ($groups as $group) {
            $users = $group['users'];
            $group['trading_user_count'] = count($users);
            foreach (['valid_trading_user_count', 'profitable_user_count', 'losing_user_count', 'breakeven_user_count',
                'order_count', 'win_order_count', 'lose_order_count', 'refund_order_count'] as $field) $group[$field] = 0;
            foreach (['stake_amount', 'valid_stake_amount', 'return_amount'] as $field) $group[$field] = '0';
            foreach ($users as $user) {
                foreach (['win', 'loss', 'refund'] as $outcome) {
                    $group[$outcome === 'loss' ? 'lose_order_count' : $outcome . '_order_count'] += $user[$outcome];
                    $group['order_count'] += $user[$outcome];
                }
                if ($user['win'] + $user['loss'] > 0) $group['valid_trading_user_count']++;
                $group['stake_amount'] = bcadd($group['stake_amount'], $user['stake'], 16);
                $group['valid_stake_amount'] = bcadd($group['valid_stake_amount'], $user['valid_stake'], 16);
                $group['return_amount'] = bcadd($group['return_amount'], $user['return'], 16);
                $net = bcsub($user['return'], $user['stake'], 16);
                $cmp = bccomp($net, '0', 16);
                $group[$cmp > 0 ? 'profitable_user_count' : ($cmp < 0 ? 'losing_user_count' : 'breakeven_user_count')]++;
            }
            $group['user_net_pnl'] = bcsub($group['return_amount'], $group['stake_amount'], 16);
            $group['platform_pnl'] = bcsub('0', $group['user_net_pnl'], 16);
            $group['payout_rate'] = bccomp($group['stake_amount'], '0', 16) === 0 ? '0'
                : bcmul(bcdiv($group['return_amount'], $group['stake_amount'], 12), '100', 8);
            unset($group['users']);
            $rows[] = $group;
        }
        return $rows;
    }

    private function emptyRow(string $date, array $source, string $play, string $symbol, bool $completed): array
    {
        return ['stat_date' => $date, 'source_type' => $source['source_type'], 'source_id' => (string) $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'], 'play_type' => $play,
            'symbol' => $symbol, 'settle_asset' => 'USDT', 'status' => $completed ? 1 : 0, 'users' => []];
    }

    private function key(array $source, string $play, string $symbol): string
    {
        return implode(':', [$source['source_type'], $source['source_id'], $play, $symbol]);
    }

    private function decimal(mixed $value): string
    {
        $text = (string) $value;
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $text)) throw new InvalidArgumentException('预测金额格式无效');
        return $text;
    }
}
