<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class FeeReportStore
{
    public function __construct(private ConnectionInterface $database) {}

    private function table(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . FeeReportSchema::TABLE;
    }

    /** Validate before the atomic replacement; failed publication preserves the previous day. */
    public function replace(string $date, array $rows): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            if ($row['stat_date'] !== $date) throw new InvalidArgumentException('统计日期不一致');
            $row['identities'] = json_encode($row['identities'], JSON_THROW_ON_ERROR);
            $row['updated_at'] = $now;
        }
        unset($row);
        $this->database->transaction(function () use ($date, $rows): void {
            $this->database->table($this->table())->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 100) as $chunk) $this->database->table($this->table())->insert($chunk);
        });
        return count($rows);
    }

    /** The merchant and site are supplied by authenticated server context, never request input. */
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
        if ($symbol !== '' && preg_match('/^[A-Z0-9]+$/D', $symbol) && !preg_match('/(?:USDT|USDC|FDUSD|BUSD|USD)$/D', $symbol)) $symbol .= 'USDT';
        if (strlen($symbol) > 64) throw new InvalidArgumentException('合约格式不正确');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        $allowed = array_merge(['stat_date', 'source_id', 'source_code', 'source_name', 'source_type', 'stat_status'], FeeReportAggregator::COUNTS, FeeReportAggregator::AMOUNTS);
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        return $this->database->transaction(function () use ($params, $scope, $start, $end, $symbol, $period, $sort, $page, $size): array {
            if ($this->database->getDriverName() === 'pgsql' && $this->database->transactionLevel() === 1) {
                $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            if ($period === 'day') return $this->dailyPage($params, $scope, $start, $end, $symbol, $sort, $page, $size);
            $query = $this->database->table($this->table())->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('symbol', $symbol === '' ? [''] : ['', $symbol]);
            $this->constrainSource($query, $params, $scope);
            $daily = [];
            foreach ($query->get() as $record) {
                $row = (array) $record;
                $key = implode(':', [$row['stat_date'], $row['source_type'], $row['source_id']]);
                if ($row['symbol'] === $symbol) $daily[$key] = $row;
                elseif (!isset($daily[$key])) {
                    foreach (FeeReportAggregator::COUNTS as $field) $row[$field] = 0;
                    foreach (FeeReportAggregator::AMOUNTS as $field) $row[$field] = '0';
                    $row['symbol'] = $symbol;
                    $row['identities'] = ['users' => []];
                    $daily[$key] = $row;
                }
            }
            $groups = [];
            foreach ($daily as $row) $groups[substr($row['stat_date'], 0, 7) . ':' . $row['source_type'] . ':' . $row['source_id']][] = $row;
            $aggregator = new FeeReportAggregator();
            $rows = array_map(fn (array $days): array => $this->responseRow($aggregator->mergeDaily($days, substr($days[0]['stat_date'], 0, 7)), 'month', $symbol), array_values($groups));
            $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 1 : -1;
            usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
                $cmp = is_numeric($a[$sort]) && is_numeric($b[$sort]) ? bccomp((string) $a[$sort], (string) $b[$sort], 16) : strcmp((string) $a[$sort], (string) $b[$sort]);
                return $direction * $cmp ?: strcmp($a['id'], $b['id']);
            });
            return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows), 'page' => $page, 'limit' => $size];
        });
    }

    /** Daily lists only read scalar metrics, never the potentially large identity JSON. */
    private function dailyPage(array $params, ?MerchantReportScope $scope, CarbonImmutable $start, CarbonImmutable $end, string $symbol, string $sort, int $page, int $size): array
    {
        $query = $this->database->table($this->table() . ' as totals')->where('totals.symbol', '')
            ->whereBetween('totals.stat_date', [$start->toDateString(), $end->toDateString()]);
        $this->constrainSource($query, $params, $scope, 'totals.');
        $count = (clone $query)->count();
        $query->select(['totals.stat_date', 'totals.source_type', 'totals.source_id', 'totals.source_code', 'totals.source_name', 'totals.updated_at']);
        if ($symbol !== '') $query->leftJoin($this->table() . ' as detail', static function ($join) use ($symbol): void {
            foreach (['stat_date', 'source_type', 'source_id'] as $key) $join->on('detail.' . $key, '=', 'totals.' . $key);
            $join->where('detail.symbol', '=', $symbol);
        });
        $expressions = [];
        foreach (array_merge(FeeReportAggregator::COUNTS, FeeReportAggregator::AMOUNTS) as $field) {
            $expressions[$field] = $symbol === '' ? 'totals.' . $field : 'COALESCE(detail.' . $field . ', 0)';
            $query->selectRaw($expressions[$field] . ' AS ' . $field);
        }
        $status = $symbol === '' ? 'totals.status' : 'COALESCE(detail.status, totals.status)';
        $query->selectRaw($status . ' AS status');
        $expression = $sort === 'stat_status' ? $status : ($expressions[$sort] ?? 'totals.' . $sort);
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderByRaw($expression . ' ' . $direction)->orderBy('totals.stat_date')->orderBy('totals.source_type')->orderBy('totals.source_id');
        $rows = $query->offset(($page - 1) * $size)->limit($size)->get()->map(fn ($row): array => $this->responseRow((array) $row, 'day', $symbol))->all();
        return ['lists' => $rows, 'count' => $count, 'page' => $page, 'limit' => $size];
    }

    private function constrainSource(Builder $query, array $params, ?MerchantReportScope $scope, string $prefix = ''): void
    {
        if ($scope !== null) {
            $query->where($prefix . 'source_type', 'merchant')->where($prefix . 'source_id', $scope->merchantId);
            $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
            $query->whereExists(static function ($merchant) use ($table, $scope): void {
                $merchant->selectRaw('1')->from($table)->where('id', $scope->merchantId)->where('pid', 0)->whereNull('deleted_at');
            });
            return;
        }
        $type = (string) ($params['source_type'] ?? '');
        if ($type !== '' && !in_array($type, ['merchant', 'tenant', 'platform'], true)) throw new InvalidArgumentException('无效的来源类型');
        $query->whereIn($prefix . 'source_type', $type === '' ? ['merchant', 'tenant'] : [$type]);
        $id = (string) ($params['source_id'] ?? '');
        if ($id !== '') {
            if (!ctype_digit($id) || strlen($id) > 19 || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) throw new InvalidArgumentException('无效的来源ID');
            $query->where($prefix . 'source_id', $id);
        }
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword === '') return;
        if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $id = ctype_digit($keyword) ? (ltrim($keyword, '0') ?: '0') : null;
        if ($id !== null && (strlen($id) > 19 || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0))) $id = null;
        $query->where(static function ($search) use ($prefix, $pattern, $id): void {
            $search->whereRaw("LOWER({$prefix}source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            if ($id !== null) $search->orWhere($prefix . 'source_id', $id);
        });
    }

    private function responseRow(array $row, string $period, string $symbol): array
    {
        $row['symbol'] = $symbol; $row['source_id'] = (string) $row['source_id'];
        $row['id'] = implode(':', [$period, $row['stat_date'], $row['source_type'], $row['source_id'], $symbol]);
        $row['stat_status'] = (int) $row['status'];
        foreach (FeeReportAggregator::COUNTS as $field) $row[$field] = (int) $row[$field];
        foreach (FeeReportAggregator::AMOUNTS as $field) $row[$field] = (string) $row[$field];
        unset($row['identities']);
        return $row;
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
