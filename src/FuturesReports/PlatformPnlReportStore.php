<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use SixMm\Shared\PredictionReports\PredictionUserPnlSchema;

/** Merge independently published contract and prediction daily facts by UTC date and source. */
final class PlatformPnlReportStore
{
    private const AMOUNTS = ['contract_trade_pnl', 'contract_trading_fee_income',
        'contract_liquidation_fee_income', 'platform_funding_fee_net', 'contract_total_pnl',
        'prediction_business_pnl', 'total_pnl'];

    public function __construct(private ConnectionInterface $database) {}

    public function replace(string $date, array $rows): int
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            if (($row['stat_date'] ?? null) !== $date) throw new InvalidArgumentException('统计日期不一致');
            $row['updated_at'] = $now;
        }
        unset($row);
        $this->database->transaction(function () use ($date, $rows): void {
            $this->database->table($this->contractTable())->where('stat_date', $date)->delete();
            foreach (array_chunk($rows, 100) as $chunk) $this->database->table($this->contractTable())->insert($chunk);
        });
        return count($rows);
    }

    public function search(array $params, ?string $merchantAccountId = null): array
    {
        $period = (string) ($params['period'] ?? 'day');
        if (!in_array($period, ['day', 'month'], true)) throw new InvalidArgumentException('无效的统计周期');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(89));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($period === 'month') { $start = $start->startOfMonth(); $end = $end->endOfMonth()->startOfDay(); }
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $order = (string) ($params['order_by'] ?? 'stat_date');
        if (!in_array($order, array_merge(['stat_date', 'source_id', 'source_code', 'source_name', 'source_type', 'stat_status'], self::AMOUNTS), true)) {
            throw new InvalidArgumentException('不支持的排序字段');
        }
        $direction = (string) ($params['order_dir'] ?? 'desc');
        if (!in_array($direction, ['asc', 'desc'], true)) throw new InvalidArgumentException('无效的排序方向');
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        $scope = $merchantAccountId === null ? null : MerchantReportScope::forAccount($this->database, $merchantAccountId);
        $type = $scope === null ? (string) ($params['source_type'] ?? 'platform') : 'merchant';
        if (!in_array($type, ['platform', 'tenant', 'merchant'], true)) throw new InvalidArgumentException('无效的来源类型');
        $sourceId = $scope?->merchantId ?? trim((string) ($params['source_id'] ?? ''));
        if ($sourceId !== '' && (!preg_match('/^\d{1,19}$/D', $sourceId)
            || (strlen($sourceId) === 19 && strcmp($sourceId, '9223372036854775807') > 0))) {
            throw new InvalidArgumentException('无效的来源ID');
        }
        $keyword = $scope === null ? trim((string) ($params['source_keyword'] ?? '')) : '';
        if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $rows = [];
        $this->database->transaction(function () use (&$rows, $start, $end, $type, $sourceId, $keyword): void {
            if ($this->database->getDriverName() === 'pgsql' && $this->database->transactionLevel() === 1) {
                $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $contract = $this->baseQuery($this->contractTable(), $start, $end, $type, $sourceId, $keyword)
                ->get()->map(static fn (object $item): array => (array) $item)->all();
            $prediction = $this->predictionTableExists()
                ? $this->baseQuery($this->predictionTable(), $start, $end, $type, $sourceId, $keyword)
                    ->where('play_type', 'all')->where('symbol', '*')->where('settle_asset', 'USDT')
                    ->get(['stat_date', 'source_type', 'source_id', 'source_code', 'source_name', 'platform_pnl', 'status', 'updated_at'])
                    ->map(static fn (object $item): array => (array) $item)->all()
                : [];
            foreach ($contract as $row) {
                $key = $this->key($row);
                $rows[$key] = $this->emptyRow($row);
                foreach (PlatformPnlReportAggregator::AMOUNTS as $field) $rows[$key][$field] = (string) $row[$field];
                $rows[$key]['status'] = (int) $row['status'];
                $rows[$key]['updated_at'] = $row['updated_at'];
                $rows[$key]['has_contract'] = true;
            }
            foreach ($prediction as $row) {
                $key = $this->key($row);
                $rows[$key] ??= $this->emptyRow($row);
                $rows[$key]['prediction_business_pnl'] = (string) $row['platform_pnl'];
                $rows[$key]['status'] = $this->combinedStatus((int) $rows[$key]['status'], (int) $row['status']);
                $rows[$key]['has_prediction'] = true;
                if ($rows[$key]['updated_at'] === null || $row['updated_at'] > $rows[$key]['updated_at']) $rows[$key]['updated_at'] = $row['updated_at'];
            }
        });
        foreach ($rows as &$row) {
            $row['total_pnl'] = bcadd($row['contract_total_pnl'], $row['prediction_business_pnl'], 16);
            if (!$row['has_contract'] || !$row['has_prediction']) $row['status'] = 2;
            unset($row['has_contract'], $row['has_prediction']);
        }
        unset($row);
        if ($period === 'month') $rows = $this->monthly(array_values($rows));
        else $rows = array_values($rows);
        foreach ($rows as &$row) {
            $row['id'] = implode(':', [$period, $row['stat_date'], $row['source_type'], $row['source_id']]);
            $row['stat_status'] = $row['status'];
            unset($row['status']);
        }
        unset($row);
        usort($rows, static function (array $a, array $b) use ($order, $direction): int {
            $left = (string) $a[$order]; $right = (string) $b[$order];
            $compare = in_array($order, self::AMOUNTS, true) || $order === 'stat_status'
                ? bccomp($left, $right, 16) : strcmp($left, $right);
            return ($direction === 'asc' ? 1 : -1) * $compare ?: strcmp($a['id'], $b['id']);
        });
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows),
            'page' => $page, 'limit' => $size];
    }

    private function monthly(array $daily): array
    {
        $groups = [];
        foreach ($daily as $row) {
            $month = substr((string) $row['stat_date'], 0, 7);
            $key = implode(':', [$month, $row['source_type'], $row['source_id']]);
            if (!isset($groups[$key])) {
                $groups[$key] = $row;
                $groups[$key]['stat_date'] = $month;
                $groups[$key]['status'] = 1;
                foreach (self::AMOUNTS as $field) $groups[$key][$field] = '0';
            }
            foreach (self::AMOUNTS as $field) $groups[$key][$field] = bcadd($groups[$key][$field], $row[$field], 16);
            $groups[$key]['status'] = $this->combinedStatus($groups[$key]['status'], $row['status']);
            if ($row['updated_at'] !== null && ($groups[$key]['updated_at'] === null || $row['updated_at'] > $groups[$key]['updated_at'])) {
                $groups[$key]['updated_at'] = $row['updated_at'];
            }
        }
        return array_values($groups);
    }

    private function emptyRow(array $row): array
    {
        $item = ['stat_date' => (string) $row['stat_date'], 'source_type' => (string) $row['source_type'],
            'source_id' => (string) $row['source_id'], 'source_code' => (string) $row['source_code'],
            'source_name' => (string) $row['source_name'], 'status' => (int) $row['status'], 'updated_at' => null];
        foreach (self::AMOUNTS as $field) $item[$field] = '0';
        $item['has_contract'] = false;
        $item['has_prediction'] = false;
        return $item;
    }

    private function baseQuery(string $table, CarbonImmutable $start, CarbonImmutable $end, string $type, string $id, string $keyword): Builder
    {
        $query = $this->database->table($table)->where('source_type', $type)
            ->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()]);
        if ($id !== '') $query->where('source_id', $id);
        if ($keyword !== '') {
            $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $numeric = ctype_digit($keyword) && strlen($keyword) <= 19
                && (strlen($keyword) < 19 || strcmp($keyword, '9223372036854775807') <= 0) ? $keyword : null;
            $query->where(static function (Builder $q) use ($pattern, $numeric): void {
                $q->whereRaw("LOWER(source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                if ($numeric !== null) $q->orWhere('source_id', $numeric);
            });
        }
        return $query;
    }

    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === '') return $fallback;
        try { $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC'); }
        catch (\Throwable $e) { throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD', 0, $e); }
        if (!$day || $day->toDateString() !== $value) throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD');
        return $day;
    }

    private function combinedStatus(int $left, int $right): int
    {
        return $left === 2 || $right === 2 ? 2 : ($left === 0 || $right === 0 ? 0 : 1);
    }

    private function key(array $row): string
    {
        return implode(':', [$row['stat_date'], $row['source_type'], $row['source_id']]);
    }

    private function contractTable(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . PlatformPnlReportSchema::TABLE;
    }

    private function predictionTable(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . PredictionUserPnlSchema::TABLE;
    }

    private function predictionTableExists(): bool
    {
        if ($this->database->getDriverName() === 'pgsql') {
            return $this->database->selectOne('SELECT to_regclass(?) AS table_name', [$this->predictionTable()])->table_name !== null;
        }
        return $this->database->getSchemaBuilder()->hasTable($this->predictionTable());
    }
}
