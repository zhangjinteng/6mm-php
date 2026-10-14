<?php

declare(strict_types=1);

namespace SixMm\Shared\AssetReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\MerchantReportScope;
use SixMm\Shared\FuturesReports\SourceDirectory;

/** A snapshot report: month balances are first/last snapshots, never sums across days. */
final class AssetBalanceReportStore
{
    private const MONEY = ['opening_total', 'closing_total', 'closing_available', 'closing_occupied'];

    public function __construct(private ConnectionInterface $db) {}

    public function search(array $params, ?string $merchantAccountId = null): array
    {
        $period = (string) ($params['period'] ?? 'day');
        if (!in_array($period, ['day', 'month'], true)) throw new InvalidArgumentException('无效的统计周期');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(89));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $currency = strtoupper(trim((string) ($params['currency'] ?? '')));
        if (!preg_match('/^[A-Z0-9_-]{0,16}$/D', $currency)) throw new InvalidArgumentException('币种格式无效');
        $type = (string) ($params['source_type'] ?? 'platform');
        if (!in_array($type, ['platform', 'tenant', 'merchant'], true)) throw new InvalidArgumentException('无效的来源类型');
        $scope = $merchantAccountId === null ? null : MerchantReportScope::forAccount($this->db, $merchantAccountId);
        $prefix = $this->db->getDriverName() === 'pgsql' ? 'public.' : '';
        $currencyQuery = $this->db->table($prefix . 'admin_agent_margin_daily_statistics');
        if ($scope !== null) $currencyQuery->where('agent_id', $scope->merchantId);
        $currencies = $currencyQuery->whereNotNull('currency')->where('currency', '<>', '')
            ->distinct()->pluck('currency')->map(static fn ($value): string => (string) $value)->all();
        if ($scope === null) {
            $currencies = array_merge($currencies, $this->db->table($prefix . AssetBalanceReportSchema::DIRECT_TABLE)
                ->whereNotNull('currency')->where('currency', '<>', '')->distinct()->pluck('currency')
                ->map(static fn ($value): string => (string) $value)->all());
        }
        $currencies = array_values(array_unique($currencies));
        sort($currencies);
        $query = $this->db->table($prefix . 'admin_agent_margin_daily_statistics')
            ->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()]);
        if ($scope !== null) $query->where('agent_id', $scope->merchantId);
        if ($currency !== '') $query->where('currency', $currency);
        $merchantRows = $query->get();
        $directory = (new SourceDirectory($this->db))->load();
        $groups = [];
        $merchantSeen = [];
        foreach ($merchantRows as $record) {
            $merchant = $directory['agents'][(string) $record->agent_id] ?? null;
            if ($merchant === null) continue;
            if ($record->currency === 'USDT') {
                $merchantSeen[$record->stat_date . ':' . $record->agent_id . ':' . $record->account_type] = true;
            }
            $tenantId = (string) ($directory['sites'][$merchant['site_id']] ?? '');
            $tenant = $directory['tenants'][$tenantId] ?? null;
            $sources = [$merchant];
            if ($scope === null) {
                if ($tenant !== null) $sources[] = $tenant;
                $sources[] = $this->platform();
            }
            foreach ($sources as $source) $this->add($groups, $source, $record);
        }
        // No historical direct balances exist before the new producer starts. Never substitute a live balance.
        if ($scope === null) {
            $direct = $this->db->table($prefix . AssetBalanceReportSchema::DIRECT_TABLE)
                ->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()]);
            if ($currency !== '') $direct->where('currency', $currency);
            foreach ($direct->get() as $record) {
                $tenant = $directory['tenants'][(string) $record->tenant_id] ?? null;
                if ($tenant === null) continue;
                foreach ([$tenant, $this->platform()] as $source) $this->add($groups, $source, $record);
                $directSeen[$record->stat_date . ':' . $record->tenant_id . ':' . $record->currency . ':' . $record->account_type] = true;
            }
            // Source rows without a corresponding direct snapshot are explicitly incomplete.
            foreach ($groups as &$row) {
                if ($row['source_type'] === 'merchant') continue;
                if ($row['currency'] === 'USDT') {
                    foreach ($directory['agents'] as $agent) {
                        $agentTenantId = (string) ($directory['sites'][$agent['site_id']] ?? '');
                        if ($row['source_type'] === 'tenant' && $row['source_id'] !== $agentTenantId) continue;
                        if (!isset($merchantSeen[$row['stat_date'] . ':' . $agent['source_id'] . ':' . $row['account_type']])) {
                            $row['stat_status'] = 2;
                        }
                    }
                }
                foreach ($directory['tenants'] as $tenant) {
                    if ($row['source_type'] === 'tenant' && $row['source_id'] !== (string) $tenant['source_id']) continue;
                    $key = $row['stat_date'] . ':' . $tenant['source_id'] . ':' . $row['currency'] . ':' . $row['account_type'];
                    if (!isset($directSeen[$key])) $row['stat_status'] = 2;
                }
            }
            unset($row);
        }
        $rows = array_values($groups);
        if ($scope === null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['source_type'] === $type));
            $keyword = trim((string) ($params['source_keyword'] ?? ''));
            if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
            if ($keyword !== '') $rows = array_values(array_filter($rows, static fn (array $row): bool =>
                mb_stripos($row['source_name'], $keyword, 0, 'UTF-8') !== false || $row['source_id'] === $keyword));
        }
        foreach ($rows as &$row) {
            foreach (self::MONEY as $field) if ($row[$field] === null) $row['stat_status'] = 2;
            $row['period_net_change'] = $row['opening_total'] === null || $row['closing_total'] === null
                ? null : bcsub($row['closing_total'], $row['opening_total'], 16);
        }
        unset($row);
        if ($period === 'month') $rows = $this->monthly($rows);
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        $allowed = array_merge(['stat_date', 'source_name', 'source_type', 'account_type', 'currency', 'funded_user_count', 'period_net_change', 'stat_status'], self::MONEY);
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $direction = (string) ($params['order_dir'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $left = $a[$sort]; $right = $b[$sort];
            $cmp = $left === null ? ($right === null ? 0 : -1) : ($right === null ? 1 :
                (is_numeric($left) && is_numeric($right) ? bccomp((string) $left, (string) $right, 16) : strcmp((string) $left, (string) $right)));
            return $cmp * $direction ?: strcmp($a['id'], $b['id']);
        });
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows),
            'page' => $page, 'limit' => $size, 'currencies' => $currencies];
    }

    private function add(array &$groups, array $source, object $record): void
    {
        $date = (string) $record->stat_date;
        $key = $date . ':' . $source['source_type'] . ':' . $source['source_id'] . ':' . $record->account_type . ':' . $record->currency;
        $row = $groups[$key] ?? ['id' => $key, 'stat_date' => $date, 'source_type' => $source['source_type'],
            'source_id' => (string) $source['source_id'], 'source_name' => $source['source_name'],
            'account_type' => (string) $record->account_type, 'currency' => (string) $record->currency,
            'funded_user_count' => 0, 'stat_status' => $date === CarbonImmutable::now('UTC')->toDateString() ? 0 : 1,
            'opening_total' => '0', 'closing_total' => '0', 'closing_available' => '0', 'closing_occupied' => '0'];
        $row['funded_user_count'] += (int) $record->funded_user_count;
        foreach (self::MONEY as $field) {
            $value = $record->{$field};
            $row[$field] = $value === null || $row[$field] === null ? null : bcadd($row[$field], (string) $value, 16);
        }
        $finalized = in_array($record->is_finalized, [true, 1, '1', 't', 'true'], true);
        if (!$finalized && $date !== CarbonImmutable::now('UTC')->toDateString()) $row['stat_status'] = 2;
        $groups[$key] = $row;
    }

    private function monthly(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp($a['stat_date'], $b['stat_date']));
        $months = [];
        foreach ($rows as $row) {
            $month = substr($row['stat_date'], 0, 7);
            $key = $month . ':' . $row['source_type'] . ':' . $row['source_id'] . ':' . $row['account_type'] . ':' . $row['currency'];
            if (!isset($months[$key])) {
                $months[$key] = $row;
                $months[$key]['id'] = $key;
                $months[$key]['stat_date'] = $month;
            }
            foreach (['closing_total', 'closing_available', 'closing_occupied', 'funded_user_count'] as $field) $months[$key][$field] = $row[$field];
            $months[$key]['stat_status'] = max($months[$key]['stat_status'], $row['stat_status']);
        }
        foreach ($months as &$row) $row['period_net_change'] = $row['opening_total'] === null || $row['closing_total'] === null
            ? null : bcsub($row['closing_total'], $row['opening_total'], 16);
        unset($row);
        return array_values($months);
    }

    private function platform(): array
    {
        return ['source_type' => 'platform', 'source_id' => '0', 'source_name' => '6MM'];
    }

    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === '') return $fallback;
        try { $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC'); }
        catch (\Throwable $e) { throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD', 0, $e); }
        if (!$date || $date->toDateString() !== $value) throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD');
        return $date;
    }
}
