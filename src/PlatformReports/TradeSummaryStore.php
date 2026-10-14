<?php
declare(strict_types=1);
namespace SixMm\Shared\PlatformReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\MerchantReportScope;

final class TradeSummaryStore
{
    public function __construct(private ConnectionInterface $db) {}

    public function replace(string $date, array $rows): int
    {
        foreach ($rows as &$row) {
            if ($row['stat_date'] !== $date) throw new InvalidArgumentException('统计日期不一致');
            $row['identities'] = json_encode($row['identities'], JSON_THROW_ON_ERROR);
            $row['updated_at'] = CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP');
        }
        unset($row);
        $this->db->transaction(function () use ($date, $rows): void {
            $this->db->table(TradeSummarySchema::table($this->db))->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 100) as $chunk) $this->db->table(TradeSummarySchema::table($this->db))->insert($chunk);
        });
        return count($rows);
    }

    public function search(array $params, ?string $accountId = null): array
    {
        $merchant = $accountId === null ? null : MerchantReportScope::forAccount($this->db, $accountId)->merchantId;
        $period = (string) ($params['period'] ?? 'day');
        if (!in_array($period, ['day', 'month'], true)) throw new InvalidArgumentException('无效的统计周期');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(89));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($period === 'month') { $start = $start->startOfMonth(); $end = $end->endOfMonth()->startOfDay(); }
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        $allowed = array_merge(['stat_date', 'source_id', 'source_name', 'source_type', 'stat_status'], TradeSummaryAggregator::USERS, TradeSummaryAggregator::ACTIONS, TradeSummaryAggregator::AMOUNTS);
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $page = max(1, (int) ($params['page_no'] ?? 1)); $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        $dir = ($params['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        return $this->db->transaction(function () use ($params, $merchant, $period, $start, $end, $sort, $page, $size, $dir): array {
            if ($this->db->getDriverName() === 'pgsql' && $this->db->transactionLevel() === 1) $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $query = $this->db->table(TradeSummarySchema::table($this->db))->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()]);
            $this->scope($query, $params, $merchant);
            if ($period === 'day') {
                $count = (clone $query)->count();
                $rows = $query->select(array_merge(['stat_date', 'source_type', 'source_id', 'source_code', 'source_name', 'status', 'updated_at'],
                    TradeSummaryAggregator::USERS, TradeSummaryAggregator::ACTIONS, TradeSummaryAggregator::AMOUNTS))
                    ->orderBy($sort === 'stat_status' ? 'status' : $sort, $dir)->orderBy('stat_date')->orderBy('source_type')->orderBy('source_id')
                    ->offset(($page - 1) * $size)->limit($size)->get()->map(fn ($row) => $this->response((array) $row, $period))->all();
            } else {
                $groups = []; $aggregator = new TradeSummaryAggregator();
                foreach ($query->orderBy('stat_date')->cursor() as $record) {
                    $row = (array) $record; $month = substr($row['stat_date'], 0, 7);
                    $key = $month . ':' . $row['source_type'] . ':' . $row['source_id'];
                    if (!isset($groups[$key])) { $groups[$key] = $aggregator->emptyRow($month, $row); $groups[$key]['status'] = 1; }
                    $aggregator->merge($groups[$key], $row);
                }
                $rows = array_map(fn ($row) => $this->response($aggregator->finish($row), $period), array_values($groups));
                usort($rows, static function ($a, $b) use ($sort, $dir): int {
                    $cmp = is_numeric($a[$sort]) && is_numeric($b[$sort]) ? bccomp((string) $a[$sort], (string) $b[$sort], 16) : strcmp((string) $a[$sort], (string) $b[$sort]);
                    return ($dir === 'asc' ? 1 : -1) * $cmp ?: strcmp($a['id'], $b['id']);
                });
                $count = count($rows); $rows = array_slice($rows, ($page - 1) * $size, $size);
            }
            return ['lists' => $rows, 'count' => $count, 'page' => $page, 'limit' => $size];
        });
    }

    private function scope(Builder $query, array $params, ?string $merchant): void
    {
        if ($merchant !== null) { $query->where('source_type', 'merchant')->where('source_id', $merchant); return; }
        $type = (string) ($params['source_type'] ?? 'platform');
        if (!in_array($type, ['platform', 'merchant', 'tenant'], true)) throw new InvalidArgumentException('无效的来源类型');
        $query->where('source_type', $type);
        $id = (string) ($params['source_id'] ?? '');
        if ($id !== '') { if (!$this->bigint($id)) throw new InvalidArgumentException('无效的来源ID'); $query->where('source_id', $id); }
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword === '') return;
        if (mb_strlen($keyword) > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $query->where(function (Builder $search) use ($keyword, $pattern): void {
            $search->whereRaw("LOWER(source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            if ($this->bigint($keyword)) $search->orWhere('source_id', ltrim($keyword, '0') ?: '0');
        });
    }

    private function bigint(string $id): bool { return ctype_digit($id) && strlen(ltrim($id, '0')) <= 19 && bccomp($id, '9223372036854775807', 0) <= 0; }
    private function response(array $row, string $period): array
    {
        $row['source_id'] = (string) $row['source_id']; $row['id'] = $period . ':' . $row['stat_date'] . ':' . $row['source_type'] . ':' . $row['source_id'];
        $row['stat_status'] = (int) $row['status'];
        foreach (array_merge(TradeSummaryAggregator::USERS, TradeSummaryAggregator::ACTIONS) as $key) $row[$key] = (int) $row[$key];
        foreach (TradeSummaryAggregator::AMOUNTS as $key) $row[$key] = bcadd((string) $row[$key], '0', 16);
        unset($row['identities'], $row['status']); return $row;
    }
    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === '') return $fallback;
        try { $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC'); }
        catch (\Throwable $e) { throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD', 0, $e); }
        if (!$date || $date->toDateString() !== $value) throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD'); return $date;
    }
}
