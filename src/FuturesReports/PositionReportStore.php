<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class PositionReportStore
{
    private const COUNTS = ['position_user_count', 'long_position_user_count', 'short_position_user_count'];
    private const AMOUNTS = ['long_position_amount', 'short_position_amount', 'closing_position_amount', 'occupied_margin', 'unrealized_pnl', 'average_leverage'];

    public function __construct(private ConnectionInterface $database) {}

    private function table(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . PositionReportSchema::TABLE;
    }

    public function replace(string $date, array $rows): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            if ($row['stat_date'] !== $date) throw new InvalidArgumentException('统计日期不一致');
            $row['updated_at'] = $now;
        }
        unset($row);
        $this->database->transaction(function () use ($date, $rows): void {
            $this->database->table($this->table())->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 100) as $chunk) $this->database->table($this->table())->insert($chunk);
        });
        return count($rows);
    }

    /** One-time legacy migration: never overwrite rows already published by the shared producer. */
    public function insertMissingMerchantRows(string $date, array $rows): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $inserted = 0;
        $this->database->transaction(function () use ($date, $rows, $now, &$inserted): void {
            foreach ($rows as $row) {
                if ($row['stat_date'] !== $date || $row['source_type'] !== 'merchant') {
                    throw new InvalidArgumentException('历史回填只允许商户来源和指定日期');
                }
                $row['updated_at'] = $now;
                $inserted += $this->database->table($this->table())->insertOrIgnore($row);
            }
        });
        return $inserted;
    }

    public function search(array $params, ?string $merchantId = null, ?string $merchantSiteId = null): array
    {
        $scope = $merchantId === null ? null : MerchantReportScope::forAccount($this->database, $merchantId, $merchantSiteId);
        $period = (string) ($params['period'] ?? 'day');
        if (!in_array($period, ['day', 'month'], true)) throw new InvalidArgumentException('无效的统计周期');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(89));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($period === 'month') { $start = $start->startOfMonth(); $end = $end->endOfMonth()->startOfDay(); }
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $symbol = strtoupper(trim((string) ($params['symbol'] ?? '')));
        if (strlen($symbol) > 64 || !preg_match('/^[A-Z0-9_:\/-]*$/D', $symbol)) throw new InvalidArgumentException('合约格式不正确');
        if ($symbol !== '' && preg_match('/^[A-Z0-9]+$/D', $symbol)
            && !preg_match('/(?:USDT|USDC|FDUSD|BUSD|USD)$/D', $symbol)) $symbol .= 'USDT';
        if (strlen($symbol) > 64) throw new InvalidArgumentException('合约格式不正确');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        $allowed = array_merge(['stat_date', 'source_id', 'source_code', 'source_name', 'source_type', 'stat_status'], self::COUNTS, self::AMOUNTS);
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        return $this->database->transaction(function () use ($params, $scope, $period, $start, $end, $symbol, $sort, $page, $size): array {
            if ($this->database->getDriverName() === 'pgsql' && $this->database->transactionLevel() === 1) {
                $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $query = $this->database->table($this->table() . ' as totals')->where('totals.symbol', '')
                ->whereBetween('totals.stat_date', [$start->toDateString(), $end->toDateString()]);
            $this->applyScope($query, $params, $scope);
            if ($symbol !== '') {
                $query->leftJoin($this->table() . ' as detail', static function ($join) use ($symbol): void {
                    foreach (['stat_date', 'source_type', 'source_id'] as $key) $join->on('detail.' . $key, '=', 'totals.' . $key);
                    $join->where('detail.symbol', '=', $symbol);
                });
            }
            $query->select(['totals.stat_date', 'totals.source_type', 'totals.source_id', 'totals.source_code', 'totals.source_name', 'totals.updated_at']);
            foreach (array_merge(self::COUNTS, self::AMOUNTS) as $field) {
                $query->selectRaw(($symbol === '' ? 'totals.' . $field : 'COALESCE(detail.' . $field . ', 0)') . ' AS ' . $field);
            }
            $status = $symbol === '' ? 'totals.status' : 'COALESCE(detail.status, totals.status)';
            $query->selectRaw($status . ' AS status');
            $query->selectRaw(($symbol === '' ? 'totals.snapshot_at' : 'COALESCE(detail.snapshot_at, totals.snapshot_at)') . ' AS snapshot_at');
            $rows = $query->get()->map(static fn ($row): array => (array) $row)->all();
            if ($period === 'month') {
                // A monthly position report is the last available snapshot, never a sum.
                $latest = [];
                foreach ($rows as $row) {
                    $key = substr((string) $row['stat_date'], 0, 7) . ':' . $row['source_type'] . ':' . $row['source_id'];
                    if (!isset($latest[$key]) || strcmp((string) $row['stat_date'], (string) $latest[$key]['stat_date']) > 0) $latest[$key] = $row;
                }
                $rows = array_values($latest);
            }
            $rows = array_map(function (array $row) use ($period, $symbol): array {
                $row['source_id'] = (string) $row['source_id'];
                $row['id'] = implode(':', [$period, $row['stat_date'], $row['source_type'], $row['source_id'], $symbol]);
                if ($period === 'month') $row['stat_date'] = substr((string) $row['stat_date'], 0, 7);
                $row['symbol'] = $symbol;
                $row['stat_status'] = (int) $row['status'];
                foreach (self::AMOUNTS as $field) $row[$field] = (string) $row[$field];
                return $row;
            }, $rows);
            $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 1 : -1;
            $sortKey = $sort === 'stat_status' ? 'stat_status' : $sort;
            usort($rows, static function (array $a, array $b) use ($sortKey, $direction): int {
                $cmp = is_numeric($a[$sortKey]) && is_numeric($b[$sortKey])
                    ? bccomp((string) $a[$sortKey], (string) $b[$sortKey], 16)
                    : strcmp((string) $a[$sortKey], (string) $b[$sortKey]);
                return $direction * $cmp ?: strcmp($a['id'], $b['id']);
            });
            return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows), 'page' => $page, 'limit' => $size];
        });
    }

    private function applyScope(Builder $query, array $params, ?MerchantReportScope $scope): void
    {
        if ($scope !== null) {
            $query->where('totals.source_type', 'merchant')->where('totals.source_id', $scope->merchantId);
            $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
            $query->whereExists(static function ($merchant) use ($table, $scope): void {
                $merchant->selectRaw('1')->from($table)->where('id', $scope->merchantId)
                    ->where('pid', 0)->whereNull('deleted_at')->where('home_site_id', $scope->siteId);
            });
            return;
        }
        $type = (string) ($params['source_type'] ?? '');
        if ($type !== '' && !in_array($type, ['merchant', 'tenant', 'platform'], true)) throw new InvalidArgumentException('无效的来源类型');
        $query->whereIn('totals.source_type', $type === '' ? ['merchant', 'tenant'] : [$type]);
        $id = (string) ($params['source_id'] ?? '');
        if ($id !== '') {
            if (!preg_match('/^\d+$/D', $id)) throw new InvalidArgumentException('无效的来源ID');
            $query->where('totals.source_id', $id);
        }
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword !== '') {
            if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
            $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $numericId = ctype_digit($keyword) ? (ltrim($keyword, '0') ?: '0') : null;
            if ($numericId !== null && (strlen($numericId) > 19 || (strlen($numericId) === 19 && strcmp($numericId, '9223372036854775807') > 0))) $numericId = null;
            $query->where(static function ($search) use ($pattern, $numericId): void {
                $search->whereRaw("LOWER(totals.source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                if ($numericId !== null) $search->orWhere('totals.source_id', $numericId);
            });
        }
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
