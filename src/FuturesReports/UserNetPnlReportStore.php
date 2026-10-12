<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class UserNetPnlReportStore
{
    public function __construct(private ConnectionInterface $database) {}

    /** Replace only one complete UTC day, after all rows have been validated. */
    public function replace(string $date, array $rows): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            if (($row['stat_date'] ?? null) !== $date) throw new InvalidArgumentException('统计日期不一致');
            $row['updated_at'] = $now;
        }
        unset($row);
        $this->database->transaction(function () use ($date, $rows): void {
            $this->database->table($this->table())->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 100) as $chunk) $this->database->table($this->table())->insert($chunk);
        });
        return count($rows);
    }

    /** Merchant and site restrictions are derived from authentication, never from query parameters. */
    public function search(array $params, ?string $merchantId = null, ?string $merchantSiteId = null): array
    {
        $scope = $merchantId === null ? null : MerchantReportScope::forAccount($this->database, $merchantId, $merchantSiteId);
        $period = (string) ($params['period'] ?? 'day');
        if (!in_array($period, ['day', 'month'], true)) throw new InvalidArgumentException('无效的统计周期');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(89));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($period === 'month') {
            $start = $start->startOfMonth();
            $end = $end->endOfMonth()->startOfDay();
        }
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        $allowed = array_merge(['stat_date', 'source_id', 'source_code', 'source_name', 'source_type', 'stat_status'],
            UserNetPnlReportAggregator::COUNTS, UserNetPnlReportAggregator::AMOUNTS);
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $dir = strtolower((string) ($params['order_dir'] ?? 'desc'));
        if (!in_array($dir, ['asc', 'desc'], true)) throw new InvalidArgumentException('无效的排序方向');
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        $query = $this->database->table($this->table())->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()]);
        if ($scope !== null) {
            $query->where('source_type', 'merchant')->where('source_id', $scope->merchantId);
            $this->constrainMerchantSite($query, $scope);
        } else {
            $type = (string) ($params['source_type'] ?? 'platform');
            if (!in_array($type, ['platform', 'tenant', 'merchant'], true)) throw new InvalidArgumentException('无效的来源类型');
            $query->where('source_type', $type);
            $id = trim((string) ($params['source_id'] ?? ''));
            if ($id !== '') {
                if (!preg_match('/^\d{1,19}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
                    throw new InvalidArgumentException('无效的来源ID');
                }
                $query->where('source_id', $id);
            }
            $this->applySourceKeyword($query, $params);
        }
        if ($period === 'day') {
            $count = (clone $query)->count();
            $rows = $query->orderBy($sort === 'stat_status' ? 'status' : $sort, $dir)
                ->orderBy('stat_date')->orderBy('source_type')->orderBy('source_id')
                ->offset(($page - 1) * $size)->limit($size)->get()->map(fn ($row): array => $this->responseRow((array) $row, 'day'))->all();
            return ['lists' => $rows, 'count' => $count, 'page' => $page, 'limit' => $size];
        }
        $groups = [];
        foreach ($query->get() as $record) {
            $row = (array) $record;
            $key = substr((string) $row['stat_date'], 0, 7) . ':' . $row['source_type'] . ':' . $row['source_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = $row;
                $groups[$key]['stat_date'] = substr((string) $row['stat_date'], 0, 7);
                foreach (UserNetPnlReportAggregator::COUNTS as $field) $groups[$key][$field] = 0;
                foreach (UserNetPnlReportAggregator::AMOUNTS as $field) $groups[$key][$field] = '0';
                $groups[$key]['status'] = 1;
            }
            foreach (UserNetPnlReportAggregator::COUNTS as $field) $groups[$key][$field] += (int) $row[$field];
            foreach (UserNetPnlReportAggregator::AMOUNTS as $field) $groups[$key][$field] = bcadd((string) $groups[$key][$field], (string) $row[$field], 16);
            if ((int) $row['status'] === 2) $groups[$key]['status'] = 2;
            elseif ((int) $row['status'] === 0 && (int) $groups[$key]['status'] !== 2) $groups[$key]['status'] = 0;
        }
        $rows = array_map(fn (array $row): array => $this->responseRow($row, 'month'), array_values($groups));
        $sortKey = $sort === 'stat_status' ? 'stat_status' : $sort;
        usort($rows, static function (array $a, array $b) use ($sortKey, $dir): int {
            $left = (string) ($a[$sortKey] ?? '');
            $right = (string) ($b[$sortKey] ?? '');
            $compare = is_numeric($left) && is_numeric($right) ? bccomp($left, $right, 16) : strcmp($left, $right);
            return ($dir === 'asc' ? 1 : -1) * $compare ?: strcmp($a['id'], $b['id']);
        });
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows), 'page' => $page, 'limit' => $size];
    }

    private function responseRow(array $row, string $period): array
    {
        $row['source_id'] = (string) $row['source_id'];
        $row['id'] = implode(':', [$period, $row['stat_date'], $row['source_type'], $row['source_id']]);
        $row['stat_status'] = (int) $row['status'];
        foreach (UserNetPnlReportAggregator::COUNTS as $field) $row[$field] = (int) $row[$field];
        foreach (UserNetPnlReportAggregator::AMOUNTS as $field) $row[$field] = (string) $row[$field];
        unset($row['status']);
        return $row;
    }

    private function applySourceKeyword(Builder $query, array $params): void
    {
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword === '') return;
        if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $id = ctype_digit($keyword) && strlen($keyword) <= 19 ? $keyword : null;
        if ($id !== null && strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0) $id = null;
        $query->where(static function (Builder $search) use ($pattern, $id): void {
            $search->whereRaw("LOWER(source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            if ($id !== null) $search->orWhere('source_id', $id);
        });
    }

    private function constrainMerchantSite(Builder $query, MerchantReportScope $scope): void
    {
        $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
        $query->whereExists(static function (Builder $merchant) use ($table, $scope): void {
            $merchant->selectRaw('1')->from($table)->where('id', $scope->merchantId)
                ->where('pid', 0)->whereNull('deleted_at')->where('home_site_id', $scope->siteId);
        });
    }

    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === '') return $fallback;
        try { $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC'); }
        catch (\Throwable $e) { throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD', 0, $e); }
        if (!$date || $date->toDateString() !== $value) throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD');
        return $date;
    }

    private function table(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . UserNetPnlReportSchema::TABLE;
    }
}
