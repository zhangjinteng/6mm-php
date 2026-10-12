<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class FundingFeeReportStore
{
    private const SORTABLE = [
        'stat_date', 'source_name', 'source_type', 'funding_settlement_user_count',
        'funding_settlement_position_amount', 'funding_settlement_count',
        'funding_fee_income', 'funding_fee_expense', 'funding_fee_net_income',
        'funding_fee_net_income_rate', 'stat_status',
    ];

    public function __construct(private ConnectionInterface $database) {}

    private function table(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . FundingFeeReportSchema::TABLE;
    }

    public function replace(string $date, array $rows): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            if ($row['stat_date'] !== $date) throw new InvalidArgumentException('统计日期不一致');
            $row['user_ids'] = json_encode($row['user_ids'], JSON_THROW_ON_ERROR);
            unset($row['funding_settlement_user_count']);
            $row['updated_at'] = $now;
        }
        unset($row);
        $this->database->transaction(function () use ($date, $rows): void {
            $this->database->table($this->table())->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 100) as $chunk) $this->database->table($this->table())->insert($chunk);
        });
        return count($rows);
    }

    /** Merchant scope and site constraint are server-derived, never accepted from request parameters. */
    public function search(array $params, ?string $merchantId = null, ?string $merchantSiteId = null): array
    {
        $scope = $merchantId === null ? null : MerchantReportScope::forAccount($this->database, $merchantId, $merchantSiteId);
        $period = (string) ($params['period'] ?? 'day');
        if (!in_array($period, ['day', 'month'], true)) throw new InvalidArgumentException('无效的统计周期');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(89));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $symbol = strtoupper(trim((string) ($params['symbol'] ?? '')));
        if (strlen($symbol) > 64 || !preg_match('/^[A-Z0-9_:\/-]*$/D', $symbol)) throw new InvalidArgumentException('合约格式不正确');
        if ($symbol !== '' && preg_match('/^[A-Z0-9]+$/D', $symbol)
            && !preg_match('/(?:USDT|USDC|FDUSD|BUSD|USD)$/D', $symbol)) $symbol .= 'USDT';
        if (strlen($symbol) > 64) throw new InvalidArgumentException('合约格式不正确');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        if ($sort === 'funding_fee') $sort = 'funding_fee_net_income'; // Legacy column key.
        if (!in_array($sort, self::SORTABLE, true)) throw new InvalidArgumentException('不支持的排序字段');
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));

        $query = $this->database->table($this->table() . ' as totals')
            ->where('totals.symbol', '')->whereBetween('totals.stat_date', [$start->toDateString(), $end->toDateString()]);
        $this->scope($query, $params, $scope);
        if ($period === 'day') {
            return $this->daily($query, $symbol, $sort, $direction, $page, $size);
        }
        return $this->monthly($query, $symbol, $sort, $direction, $page, $size);
    }

    private function daily(Builder $query, string $symbol, string $sort, string $direction, int $page, int $size): array
    {
        $count = (clone $query)->count();
        $columns = ['stat_date', 'source_type', 'source_id', 'source_code', 'source_name', 'updated_at'];
        foreach ($columns as $column) $query->addSelect('totals.' . $column);
        if ($symbol !== '') $this->joinDetail($query, $symbol);
        $metricColumns = ['funding_settlement_count', ...FundingFeeReportAggregator::AMOUNTS];
        foreach ($metricColumns as $column) $query->selectRaw($symbol === '' ? "totals.{$column} AS {$column}" : "COALESCE(detail.{$column}, 0) AS {$column}");
        // The distinct user set cannot be selected for a daily page. Its size may be large.
        $userExpression = $symbol === '' ? 'totals.user_ids' : "COALESCE(detail.user_ids, '[]')";
        $countExpression = $this->database->getDriverName() === 'pgsql'
            ? 'json_array_length(' . $userExpression . '::json)'
            : 'json_array_length(' . $userExpression . ')';
        $query->selectRaw($countExpression . ' AS funding_settlement_user_count');
        $statusExpression = $symbol === '' ? 'totals.status' : 'COALESCE(detail.status, totals.status)';
        $query->selectRaw($statusExpression . ' AS status');
        $sortExpression = match ($sort) {
            'stat_status' => $statusExpression,
            'funding_settlement_user_count' => $countExpression,
            'funding_fee_net_income_rate' => '(CASE WHEN ' . ($symbol === '' ? 'totals' : 'detail') . '.funding_settlement_position_amount > 0 THEN '
                . ($symbol === '' ? 'totals' : 'detail') . '.funding_fee_net_income / '
                . ($symbol === '' ? 'totals' : 'detail') . '.funding_settlement_position_amount ELSE 0 END)',
            default => in_array($sort, $metricColumns, true)
                ? ($symbol === '' ? "totals.{$sort}" : "COALESCE(detail.{$sort}, 0)")
                : 'totals.' . $sort,
        };
        $query->orderByRaw($sortExpression . ' ' . $direction)
            ->orderBy('totals.stat_date', 'desc')->orderBy('totals.source_type')->orderBy('totals.source_id');
        $rows = $query->offset(($page - 1) * $size)->limit($size)->get()
            ->map(fn (object $row): array => $this->responseRow((array) $row, 'day', $symbol))->all();
        return ['lists' => $rows, 'count' => $count, 'page' => $page, 'limit' => $size];
    }

    private function monthly(Builder $query, string $symbol, string $sort, string $direction, int $page, int $size): array
    {
        if ($symbol !== '') $this->joinDetail($query, $symbol);
        $fields = ['stat_date', 'source_type', 'source_id', 'source_code', 'source_name', 'updated_at', 'status', 'user_ids',
            'funding_settlement_count', ...FundingFeeReportAggregator::AMOUNTS];
        foreach ($fields as $field) {
            $query->selectRaw(($symbol === '' ? "totals.{$field}" : (in_array($field, ['stat_date', 'source_type', 'source_id', 'source_code', 'source_name', 'updated_at'], true)
                ? "totals.{$field}" : "COALESCE(detail.{$field}, " . ($field === 'status' ? "totals.status" : ($field === 'user_ids' ? "'[]'" : '0')) . ')')) . " AS {$field}");
        }
        $groups = [];
        foreach ($query->get() as $record) {
            $row = (array) $record;
            $month = substr((string) $row['stat_date'], 0, 7);
            $key = implode(':', [$month, $row['source_type'], $row['source_id']]);
            if (!isset($groups[$key])) {
                $groups[$key] = $row;
                $groups[$key]['stat_date'] = $month;
                $groups[$key]['user_ids'] = [];
                $groups[$key]['funding_settlement_count'] = 0;
                foreach (FundingFeeReportAggregator::AMOUNTS as $field) $groups[$key][$field] = '0';
            }
            foreach (json_decode((string) $row['user_ids'], true, 512, JSON_THROW_ON_ERROR) as $id) $groups[$key]['user_ids'][(string) $id] = true;
            $groups[$key]['funding_settlement_count'] += (int) $row['funding_settlement_count'];
            foreach (FundingFeeReportAggregator::AMOUNTS as $field) {
                $groups[$key][$field] = bcadd($groups[$key][$field], (string) $row[$field], 16);
            }
            if ((int) $row['status'] === 2) $groups[$key]['status'] = 2;
            elseif ((int) $row['status'] === 0 && (int) $groups[$key]['status'] !== 2) $groups[$key]['status'] = 0;
            if ((string) $row['updated_at'] > (string) $groups[$key]['updated_at']) $groups[$key]['updated_at'] = $row['updated_at'];
        }
        $rows = array_map(function (array $row) use ($symbol): array {
            $row['funding_settlement_user_count'] = count($row['user_ids']);
            unset($row['user_ids']);
            return $this->responseRow($row, 'month', $symbol);
        }, array_values($groups));
        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $left = $a[$sort]; $right = $b[$sort];
            $comparison = is_numeric($left) && is_numeric($right)
                ? bccomp((string) $left, (string) $right, 16) : strcmp((string) $left, (string) $right);
            return ($direction === 'asc' ? $comparison : -$comparison) ?: strcmp($a['id'], $b['id']);
        });
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows), 'page' => $page, 'limit' => $size];
    }

    private function joinDetail(Builder $query, string $symbol): void
    {
        $query->leftJoin($this->table() . ' as detail', static function ($join) use ($symbol): void {
            foreach (['stat_date', 'source_type', 'source_id'] as $key) $join->on('detail.' . $key, '=', 'totals.' . $key);
            $join->where('detail.symbol', '=', $symbol);
        });
    }

    private function scope(Builder $query, array $params, ?MerchantReportScope $merchant): void
    {
        if ($merchant !== null) {
            $query->where('totals.source_type', 'merchant')->where('totals.source_id', $merchant->merchantId);
            $agents = ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
            $query->whereExists(static function ($sub) use ($agents, $merchant): void {
                $sub->selectRaw('1')->from($agents)->where('id', $merchant->merchantId)
                    ->where('pid', 0)->whereNull('deleted_at')->where('home_site_id', $merchant->siteId);
            });
            return;
        }
        $type = (string) ($params['source_type'] ?? '');
        if ($type !== '' && !in_array($type, ['merchant', 'tenant', 'platform'], true)) throw new InvalidArgumentException('无效的来源类型');
        $query->whereIn('totals.source_type', $type === '' ? ['merchant', 'tenant'] : [$type]);
        $id = trim((string) ($params['source_id'] ?? ''));
        if ($id !== '') {
            if (!preg_match('/^\d+$/D', $id)) throw new InvalidArgumentException('无效的来源ID');
            $query->where('totals.source_id', $id);
        }
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword === '') return;
        if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $id = ctype_digit($keyword) ? (ltrim($keyword, '0') ?: '0') : null;
        if ($id !== null && (strlen($id) > 19 || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0))) $id = null;
        $query->where(static function ($nested) use ($pattern, $id): void {
            $nested->whereRaw("LOWER(totals.source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
            if ($id !== null) $nested->orWhere('totals.source_id', $id);
        });
    }

    private function responseRow(array $row, string $period, string $symbol): array
    {
        $row['source_id'] = (string) $row['source_id'];
        $row['symbol'] = $symbol;
        $row['id'] = implode(':', [$period, $row['stat_date'], $row['source_type'], $row['source_id'], $symbol]);
        $row['funding_settlement_user_count'] = (int) $row['funding_settlement_user_count'];
        $row['funding_settlement_count'] = (int) $row['funding_settlement_count'];
        foreach (FundingFeeReportAggregator::AMOUNTS as $field) $row[$field] = (string) $row[$field];
        $notional = $row['funding_settlement_position_amount'];
        $row['funding_fee_net_income_rate'] = bccomp($notional, '0', 16) > 0
            ? bcdiv(bcmul($row['funding_fee_net_income'], '100', 20), $notional, 4) : '0.0000';
        $row['stat_status'] = (int) $row['status'];
        unset($row['status'], $row['user_ids']);
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
