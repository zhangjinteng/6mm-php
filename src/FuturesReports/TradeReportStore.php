<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class TradeReportStore
{
    public function __construct(private ConnectionInterface $database) {}

    private function table(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . TradeReportSchema::TABLE;
    }

    /** Build every row before replacement; failure keeps the previously published day. */
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

    /** Both merchant ID and optional site constraint are server-derived, never request parameters. */
    public function search(array $params, ?string $merchantId = null, ?string $merchantSiteId = null): array
    {
        $merchantScope = $merchantId === null ? null : MerchantReportScope::forAccount($this->database, $merchantId, $merchantSiteId);
        $merchantId = $merchantScope?->merchantId;
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
        $symbol = strtoupper(trim((string) ($params['symbol'] ?? '')));
        if (strlen($symbol) > 64 || !preg_match('/^[A-Z0-9_:\/-]*$/D', $symbol)) throw new InvalidArgumentException('合约格式不正确');
        // A base ticker in the USDT-settled report (e.g. btc) means its USDT contract.
        // Keep complete symbols and explicitly formatted contracts as exact matches.
        if ($symbol !== '' && preg_match('/^[A-Z0-9]+$/D', $symbol)
            && !preg_match('/(?:USDT|USDC|FDUSD|BUSD|USD)$/D', $symbol)) {
            $symbol .= 'USDT';
        }
        if (strlen($symbol) > 64) throw new InvalidArgumentException('合约格式不正确');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        $allowed = ['stat_date', 'source_id', 'source_code', 'source_name', 'source_type', 'trading_user_count', 'order_count', 'order_amount', 'filled_order_count', 'fill_count', 'trade_amount', 'cancel_order_count', 'cancel_amount', 'amount_fill_rate', 'stat_status'];
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        if ($period === 'day') {
            return $this->database->transaction(function () use ($params, $merchantScope, $start, $end, $symbol, $sort, $page, $size): array {
                if ($this->database->getDriverName() === 'pgsql' && $this->database->transactionLevel() === 1) {
                    $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }
                return $this->dailyPage($params, $merchantScope, $start, $end, $symbol, $sort, $page, $size);
            });
        }
        $query = $this->database->table($this->table())->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('symbol', $symbol === '' ? [''] : ['', $symbol]);
        if ($merchantId !== null) {
            $query->where('source_type', 'merchant')->where('source_id', $merchantId);
            $this->constrainMerchantSite($query, $merchantScope);
        } else {
            $type = (string) ($params['source_type'] ?? '');
            if ($type !== '' && !in_array($type, ['merchant', 'tenant', 'platform'], true)) throw new InvalidArgumentException('无效的来源类型');
            if ($type === '') $query->whereIn('source_type', ['merchant', 'tenant']);
            else $query->where('source_type', $type);
            $id = (string) ($params['source_id'] ?? '');
            if ($id !== '') {
                if (!preg_match('/^\d+$/D', $id)) throw new InvalidArgumentException('无效的来源ID');
                $query->where('source_id', $id);
            }
            $this->applySourceKeyword($query, $params);
        }
        $daily = [];
        foreach ($query->get() as $record) {
            $row = (array) $record;
            $key = implode(':', [$row['stat_date'], $row['source_type'], $row['source_id']]);
            if ($row['symbol'] === $symbol) $daily[$key] = $row;
            elseif (!isset($daily[$key])) {
                foreach (['trading_user_count', 'order_count', 'filled_order_count', 'fill_count', 'cancel_order_count'] as $field) $row[$field] = 0;
                foreach (['order_amount', 'trade_amount', 'cancel_amount'] as $field) $row[$field] = '0';
                $row['symbol'] = $symbol;
                $row['identities'] = ['users' => [], 'orders' => [], 'executions' => []];
                $daily[$key] = $row;
            }
        }
        $rows = array_values($daily);
        if ($period === 'month') {
            $groups = [];
            foreach ($rows as $row) $groups[substr($row['stat_date'], 0, 7) . ':' . $row['source_type'] . ':' . $row['source_id']][] = $row;
            $aggregator = new TradeReportAggregator();
            $rows = array_map(static fn (array $days): array => $aggregator->mergeDaily($days, substr($days[0]['stat_date'], 0, 7)), array_values($groups));
        }
        $rows = array_map(fn (array $row): array => $this->responseRow($row, $period, $symbol), $rows);
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $cmp = is_numeric($a[$sort]) && is_numeric($b[$sort]) ? bccomp((string) $a[$sort], (string) $b[$sort], 16) : strcmp((string) $a[$sort], (string) $b[$sort]);
            return $direction * $cmp ?: strcmp($a['id'], $b['id']);
        });
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows), 'page' => $page, 'limit' => $size];
    }

    /** Daily pages never fetch the potentially large JSON identity sets. */
    private function dailyPage(array $params, ?MerchantReportScope $merchantScope, CarbonImmutable $start, CarbonImmutable $end,
        string $symbol, string $sort, int $page, int $size): array
    {
        $query = $this->database->table($this->table() . ' as totals')
            ->where('totals.symbol', '')->whereBetween('totals.stat_date', [$start->toDateString(), $end->toDateString()]);
        if ($merchantScope !== null) {
            $query->where('totals.source_type', 'merchant')->where('totals.source_id', $merchantScope->merchantId);
            $this->constrainMerchantSite($query, $merchantScope);
        } else {
            $type = (string) ($params['source_type'] ?? '');
            if ($type !== '' && !in_array($type, ['merchant', 'tenant', 'platform'], true)) throw new InvalidArgumentException('无效的来源类型');
            $query->whereIn('totals.source_type', $type === '' ? ['merchant', 'tenant'] : [$type]);
            $id = (string) ($params['source_id'] ?? '');
            if ($id !== '') {
                if (!preg_match('/^\d+$/D', $id)) throw new InvalidArgumentException('无效的来源ID');
                $query->where('totals.source_id', $id);
            }
            $this->applySourceKeyword($query, $params, 'totals.');
        }
        $count = (clone $query)->count();
        $metrics = ['trading_user_count', 'order_count', 'order_amount', 'filled_order_count', 'fill_count', 'trade_amount', 'cancel_order_count', 'cancel_amount'];
        $query->select(['totals.stat_date', 'totals.source_type', 'totals.source_id', 'totals.source_code', 'totals.source_name', 'totals.updated_at']);
        if ($symbol !== '') {
            $query->leftJoin($this->table() . ' as detail', static function ($join) use ($symbol): void {
                foreach (['stat_date', 'source_type', 'source_id'] as $key) $join->on('detail.' . $key, '=', 'totals.' . $key);
                $join->where('detail.symbol', '=', $symbol);
            });
        }
        $expressions = [];
        foreach ($metrics as $key) {
            $expressions[$key] = $symbol === '' ? 'totals.' . $key : 'COALESCE(detail.' . $key . ', 0)';
            $query->selectRaw($expressions[$key] . ' AS ' . $key);
        }
        $status = $symbol === '' ? 'totals.status' : 'COALESCE(detail.status, totals.status)';
        $query->selectRaw($status . ' AS status');
        // All identifiers/expressions below come from a fixed whitelist.
        $expression = $sort === 'stat_status' ? $status : ($expressions[$sort] ?? 'totals.' . $sort);
        if ($sort === 'amount_fill_rate') $expression = 'CASE WHEN ' . $expressions['order_amount'] . ' = 0 THEN 0 ELSE 100.0 * ' . $expressions['trade_amount'] . ' / ' . $expressions['order_amount'] . ' END';
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderByRaw($expression . ' ' . $direction)->orderBy('totals.stat_date')->orderBy('totals.source_type')->orderBy('totals.source_id');
        $rows = $query->offset(($page - 1) * $size)->limit($size)->get()->map(fn ($row): array => $this->responseRow((array) $row, 'day', $symbol))->all();
        return ['lists' => $rows, 'count' => $count, 'page' => $page, 'limit' => $size];
    }

    /** A bound literal substring, OR an exact BIGINT ID; used before counting and pagination. */
    private function applySourceKeyword(\Illuminate\Database\Query\Builder $query, array $params, string $prefix = ''): void
    {
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword === '') return;
        if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $id = ctype_digit($keyword) ? (ltrim($keyword, '0') ?: '0') : null;
        if ($id !== null && (strlen($id) > 19 || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0))) $id = null;
        $query->where(static function ($search) use ($prefix, $pattern, $id): void {
            // The prefix is internal, not supplied by the request; all text is bound.
            $search->whereRaw("LOWER({$prefix}source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            if ($id !== null) $search->orWhere($prefix . 'source_id', $id);
        });
    }

    private function responseRow(array $row, string $period, string $symbol): array
    {
        $row['symbol'] = $symbol;
        $row['source_id'] = (string) $row['source_id'];
        $row['id'] = implode(':', [$period, $row['stat_date'], $row['source_type'], $row['source_id'], $symbol]);
        $row['amount_fill_rate'] = bccomp((string) $row['order_amount'], '0', 16) === 0 ? '0' : bcmul(bcdiv((string) $row['trade_amount'], (string) $row['order_amount'], 20), '100', 8);
        $row['stat_status'] = (int) $row['status'];
        foreach (['order_amount', 'trade_amount', 'cancel_amount'] as $field) $row[$field] = (string) $row[$field];
        unset($row['identities']);
        return $row;
    }

    private function constrainMerchantSite(\Illuminate\Database\Query\Builder $query, MerchantReportScope $scope): void
    {
        $table = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
        $query->whereExists(static function ($merchant) use ($table, $scope): void {
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
}
