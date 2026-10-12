<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class PnlReportStore
{
    private const METRICS = ['trading_user_count', 'profitable_user_count', 'losing_user_count',
        'trade_amount', 'profit_amount', 'loss_amount', 'net_pnl'];

    public function __construct(private ConnectionInterface $database) {}

    private function table(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . PnlReportSchema::TABLE;
    }

    /** Replace only one validated UTC day, preserving the last good version on failure. */
    public function replace(string $date, array $rows): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            if ($row['stat_date'] !== $date) throw new InvalidArgumentException('统计日期不一致');
            $row['user_metrics'] = json_encode($row['user_metrics'], JSON_THROW_ON_ERROR);
            $row['updated_at'] = $now;
        }
        unset($row);
        $this->database->transaction(function () use ($date, $rows): void {
            $this->database->table($this->table())->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 100) as $chunk) $this->database->table($this->table())->insert($chunk);
        });
        return count($rows);
    }

    /** Merchant identity and optional site are server-derived, never accepted from query parameters. */
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
        $allowed = array_merge(['stat_date', 'source_id', 'source_code', 'source_name', 'source_type', 'stat_status'], self::METRICS);
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));

        $query = $this->database->table($this->table() . ' as totals')
            ->where('totals.symbol', '')->whereBetween('totals.stat_date', [$start->toDateString(), $end->toDateString()]);
        $this->scope($query, $params, $scope);
        if ($symbol !== '') {
            $query->leftJoin($this->table() . ' as detail', static function ($join) use ($symbol): void {
                foreach (['stat_date', 'source_type', 'source_id'] as $key) $join->on('detail.' . $key, '=', 'totals.' . $key);
                $join->where('detail.symbol', '=', $symbol);
            });
        }
        $query->select(['totals.stat_date', 'totals.source_type', 'totals.source_id',
            'totals.source_code', 'totals.source_name', 'totals.updated_at']);
        foreach (self::METRICS as $metric) {
            $expression = $symbol === '' ? 'totals.' . $metric : 'COALESCE(detail.' . $metric . ', 0)';
            $query->selectRaw($expression . ' AS ' . $metric);
        }
        $status = $symbol === '' ? 'totals.status' : 'COALESCE(detail.status, totals.status)';
        $query->selectRaw($status . ' AS status');

        if ($period === 'day') {
            return $this->database->transaction(function () use ($query, $symbol, $sort, $direction, $page, $size): array {
                if ($this->database->getDriverName() === 'pgsql' && $this->database->transactionLevel() === 1) {
                    $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }
                $count = (clone $query)->count();
                $expression = $sort === 'stat_status' ? ($symbol === '' ? 'totals.status' : 'COALESCE(detail.status, totals.status)')
                    : (in_array($sort, self::METRICS, true)
                        ? ($symbol === '' ? 'totals.' . $sort : 'COALESCE(detail.' . $sort . ', 0)')
                        : 'totals.' . $sort);
                $query->orderByRaw($expression . ' ' . $direction)->orderBy('totals.stat_date')
                    ->orderBy('totals.source_type')->orderBy('totals.source_id');
                $rows = $query->offset(($page - 1) * $size)->limit($size)->get()
                    ->map(fn ($row): array => $this->response((array) $row, 'day', $symbol))->all();
                return ['lists' => $rows, 'count' => $count, 'page' => $page, 'limit' => $size];
            });
        }

        $query->selectRaw(($symbol === '' ? 'totals.user_metrics' : "COALESCE(detail.user_metrics, '{}')") . ' AS user_metrics');
        $groups = [];
        foreach ($query->get() as $record) {
            $row = (array) $record;
            $key = substr((string) $row['stat_date'], 0, 7) . ':' . $row['source_type'] . ':' . $row['source_id'];
            $groups[$key][] = $row;
        }
        $aggregator = new PnlReportAggregator();
        $rows = array_map(fn (array $days): array => $this->response(
            $aggregator->mergeDaily($days, substr((string) $days[0]['stat_date'], 0, 7)), 'month', $symbol), array_values($groups));
        $factor = $direction === 'asc' ? 1 : -1;
        usort($rows, static function (array $left, array $right) use ($sort, $factor): int {
            $cmp = is_numeric($left[$sort]) && is_numeric($right[$sort])
                ? bccomp((string) $left[$sort], (string) $right[$sort], 16)
                : strcmp((string) $left[$sort], (string) $right[$sort]);
            return $factor * $cmp ?: strcmp($left['id'], $right['id']);
        });
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows), 'page' => $page, 'limit' => $size];
    }

    private function scope(Builder $query, array $params, ?MerchantReportScope $merchant): void
    {
        if ($merchant !== null) {
            $query->where('totals.source_type', 'merchant')->where('totals.source_id', $merchant->merchantId);
            $agents = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
            $query->whereExists(static function ($check) use ($agents, $merchant): void {
                $check->selectRaw('1')->from($agents)->where('id', $merchant->merchantId)
                    ->where('pid', 0)->whereNull('deleted_at')->where('home_site_id', $merchant->siteId);
            });
            return;
        }
        $type = (string) ($params['source_type'] ?? 'platform');
        if ($type !== '' && !in_array($type, ['merchant', 'tenant', 'platform'], true)) throw new InvalidArgumentException('无效的来源类型');
        $query->whereIn('totals.source_type', $type === '' ? ['merchant', 'tenant'] : [$type]);
        $id = (string) ($params['source_id'] ?? '');
        if ($id !== '') {
            if (!preg_match('/^\d+$/D', $id)) throw new InvalidArgumentException('无效的来源ID');
            $query->where('totals.source_id', $id);
        }
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword === '') return;
        if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $numeric = ctype_digit($keyword) ? (ltrim($keyword, '0') ?: '0') : null;
        if ($numeric !== null && (strlen($numeric) > 19 || (strlen($numeric) === 19 && strcmp($numeric, '9223372036854775807') > 0))) $numeric = null;
        $query->where(static function ($search) use ($pattern, $numeric): void {
            $search->whereRaw("LOWER(totals.source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            if ($numeric !== null) $search->orWhere('totals.source_id', $numeric);
        });
    }

    private function response(array $row, string $period, string $symbol): array
    {
        $row['symbol'] = $symbol;
        $row['source_id'] = (string) $row['source_id'];
        $row['id'] = implode(':', [$period, $row['stat_date'], $row['source_type'], $row['source_id'], $symbol]);
        $row['stat_status'] = (int) $row['status'];
        foreach (['trade_amount', 'profit_amount', 'loss_amount', 'net_pnl'] as $key) $row[$key] = (string) $row[$key];
        foreach (['trading_user_count', 'profitable_user_count', 'losing_user_count'] as $key) $row[$key] = (int) $row[$key];
        unset($row['user_metrics'], $row['status']);
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
