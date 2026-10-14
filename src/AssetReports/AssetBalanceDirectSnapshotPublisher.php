<?php

declare(strict_types=1);

namespace SixMm\Shared\AssetReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\SourceDirectory;

/** Captures only direct users (agent_id=0); merchant snapshots remain Agent's responsibility. */
final class AssetBalanceDirectSnapshotPublisher
{
    public function __construct(private ConnectionInterface $db, private ConnectionInterface $assetDb) {}

    public function capture(CarbonImmutable $at, bool $boundary = false): int
    {
        $at = $at->setTimezone('UTC');
        if ($boundary && $at->format('H:i') !== '00:00') throw new InvalidArgumentException('日终余额必须在UTC零点采集');
        if ($at->toDateString() !== CarbonImmutable::now('UTC')->toDateString()) {
            throw new InvalidArgumentException('不能用当前余额回填历史日期');
        }
        $directory = (new SourceDirectory($this->db))->load();
        $prefix = $this->db->getDriverName() === 'pgsql' ? 'public.' : '';
        $users = $this->db->table($prefix . 'users')->where('agent_id', 0)->whereNull('deleted_at')
            ->where('home_site_id', '>', 0)
            ->get(['user_id', 'home_site_id']);
        $siteByUser = [];
        foreach ($users as $user) {
            $tenantId = (string) ($directory['sites'][(string) $user->home_site_id] ?? '');
            if ($tenantId === '' || !isset($directory['tenants'][$tenantId])) {
                throw new InvalidArgumentException('直客缺少有效白标来源，拒绝发布不完整资产快照');
            }
            $siteByUser[(string) $user->user_id] = $tenantId;
        }
        $date = $at->toDateString();
        $groups = [];
        // Zero rows distinguish "no direct users" from "direct snapshot was never captured".
        foreach ($directory['tenants'] as $tenant) {
            foreach (['contract', 'funding'] as $accountType) $this->zero($groups, (string) $tenant['source_id'], 'USDT', $accountType);
        }
        $merchantCurrencies = $this->db->table($prefix . 'admin_agent_margin_daily_statistics')
            ->whereIn('stat_date', $boundary ? [$date, $at->subDay()->toDateString()] : [$date])
            ->get(['agent_id', 'currency', 'account_type']);
        foreach ($merchantCurrencies as $merchantCurrency) {
            $merchant = $directory['agents'][(string) $merchantCurrency->agent_id] ?? null;
            if ($merchant === null) continue;
            $tenantId = (string) ($directory['sites'][$merchant['site_id']] ?? '');
            if ($tenantId !== '') $this->zero($groups, $tenantId, (string) $merchantCurrency->currency, (string) $merchantCurrency->account_type);
        }
        if ($boundary) {
            foreach ($this->db->table($prefix . AssetBalanceReportSchema::DIRECT_TABLE)
                ->where('stat_date', $at->subDay()->toDateString())->get(['tenant_id', 'currency', 'account_type']) as $previous) {
                $this->zero($groups, (string) $previous->tenant_id, (string) $previous->currency, (string) $previous->account_type);
            }
        }
        foreach (array_chunk(array_keys($siteByUser), 1000) as $ids) {
            $contracts = $this->db->table($prefix . 'user_assets as assets')
                ->whereIn('assets.user_id', $ids)->whereNull('assets.deleted_at')
                ->get(['assets.user_id', 'assets.asset_type', 'assets.wallet_balance', 'assets.frozen_balance']);
            foreach ($contracts as $balance) {
                $userId = (string) $balance->user_id;
                $total = (string) $balance->wallet_balance;
                $occupied = (string) ($balance->frozen_balance ?? '0');
                $this->add($groups, $siteByUser[$userId], (string) $balance->asset_type, 'contract', $userId,
                    bcsub($total, $occupied, 16), $occupied, $total);
            }
            $funding = $this->assetDb->table('funding_balances as balances')
                ->join('funding_accounts as accounts', 'accounts.account_id', '=', 'balances.account_id')
                ->join('funding_assets as assets', 'assets.asset_id', '=', 'balances.asset_id')
                ->whereIn('accounts.user_id', $ids)->where('accounts.agent_id', 0)
                ->where('accounts.status', 'ACTIVE')->where('assets.status', 'ENABLED')
                ->get(['accounts.user_id', 'assets.asset_code', 'assets.internal_scale',
                    'balances.available_amount', 'balances.held_amount']);
            foreach ($funding as $balance) {
                $userId = (string) $balance->user_id;
                $factor = bcpow('10', (string) max(0, (int) $balance->internal_scale), 0);
                $available = bcdiv((string) $balance->available_amount, $factor, 16);
                $occupied = bcdiv((string) $balance->held_amount, $factor, 16);
                $this->add($groups, $siteByUser[$userId], (string) $balance->asset_code, 'funding', $userId,
                    $available, $occupied, bcadd($available, $occupied, 16));
            }
        }
        $dimensions = [];
        foreach ($groups as $group) $dimensions[$group['currency'] . ':' . $group['account_type']] = [$group['currency'], $group['account_type']];
        foreach ($directory['tenants'] as $tenant) {
            foreach ($dimensions as [$code, $accountType]) $this->zero($groups, (string) $tenant['source_id'], $code, $accountType);
        }
        $table = $prefix . AssetBalanceReportSchema::DIRECT_TABLE;
        $stamp = $at->format('Y-m-d H:i:s');
        $this->db->transaction(function () use ($table, $groups, $date, $stamp, $boundary): void {
            foreach ($groups as $group) {
                $identity = ['stat_date' => $date, 'tenant_id' => $group['tenant_id'],
                    'currency' => $group['currency'], 'account_type' => $group['account_type']];
                $values = ['funded_user_count' => count($group['users']), 'closing_available' => $group['available'],
                    'closing_occupied' => $group['occupied'], 'closing_total' => $group['total'], 'updated_at' => $stamp];
                $this->db->table($table)->insertOrIgnore($identity + $values + [
                    'opening_total' => $boundary ? $group['total'] : null,
                    'opening_snapshot_at' => $boundary ? $stamp : null,
                ]);
                $current = $this->db->table($table)->where($identity);
                if ($boundary) {
                    $current->whereNull('opening_snapshot_at')->update(['opening_total' => $group['total'],
                        'opening_snapshot_at' => $stamp, 'updated_at' => $stamp]);
                }
                $this->db->table($table)->where($identity)->where('is_finalized', false)->update($values);
                if (!$boundary) continue;
                $previous = $identity;
                $previous['stat_date'] = CarbonImmutable::parse($date, 'UTC')->subDay()->toDateString();
                $this->db->table($table)->insertOrIgnore($previous + $values + ['is_finalized' => true, 'closing_snapshot_at' => $stamp]);
                $this->db->table($table)->where($previous)->whereNull('closing_snapshot_at')->update($values + [
                    'is_finalized' => true, 'closing_snapshot_at' => $stamp]);
            }
        });
        return count($groups);
    }

    private function zero(array &$groups, string $tenantId, string $currency, string $accountType): string
    {
        $currency = strtoupper(trim($currency));
        $key = $tenantId . ':' . $currency . ':' . $accountType;
        $groups[$key] ??= ['tenant_id' => $tenantId, 'currency' => $currency, 'account_type' => $accountType,
            'users' => [], 'available' => '0', 'occupied' => '0', 'total' => '0'];
        return $key;
    }

    private function add(array &$groups, string $tenantId, string $currency, string $accountType, string $userId,
        string $available, string $occupied, string $total): void
    {
        $key = $this->zero($groups, $tenantId, $currency, $accountType);
        $groups[$key]['available'] = bcadd($groups[$key]['available'], $available, 16);
        $groups[$key]['occupied'] = bcadd($groups[$key]['occupied'], $occupied, 16);
        $groups[$key]['total'] = bcadd($groups[$key]['total'], $total, 16);
        if (bccomp($total, '0', 16) > 0) $groups[$key]['users'][$userId] = true;
    }
}
