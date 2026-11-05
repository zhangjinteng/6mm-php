<?php

declare(strict_types=1);

namespace SixMm\Shared\AssetReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\SourceDirectory;
use SixMm\Shared\FuturesReports\MerchantReportScope;
use SixMm\Shared\FuturesReports\ReportUserScope;

/** Read-only source-keyed user assets; legacy merchant-only snapshots are not compatible. */
final class AssetReportQueryService
{
    public const FLOWS = ['deposit_in', 'withdraw_out', 'transfer_net', 'realized_pnl', 'fee_net', 'manual_adjust'];
    public const SORTABLE = ['stat_date', 'source_name', 'source_type', 'funded_user_count', 'total_assets',
        'negative_assets', 'opening_assets', 'closing_assets', 'deposit_in', 'withdraw_out', 'transfer_net',
        'realized_pnl', 'fee_net', 'manual_adjust', 'bucket_0_amount', 'bucket_1_amount', 'bucket_2_amount',
        'bucket_3_amount', 'bucket_4_amount'];
    private const BALANCE_SORTABLE = ['stat_date', 'source_name', 'source_type', 'account_type', 'currency',
        'funded_user_count', 'opening_total', 'closing_total', 'closing_available', 'closing_occupied',
        'period_net_change', 'stat_status'];

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function search(array $params, string $report): array
    {
        if (!in_array($report, ['distribution', 'changes'], true)) {
            throw new InvalidArgumentException('不支持的报表类型');
        }
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        if (!in_array($sort, self::SORTABLE, true)) {
            throw new InvalidArgumentException('不支持的排序字段');
        }
        $data = $this->buildRows($params, $report);
        return $this->paginate($data['rows'], $params, $sort)
            + ['schema_ready' => $data['schema_ready'], 'currency' => 'USDT', 'user_types' => 'all'];
    }

    /** Balance and distribution share the same cohort, snapshot and historical-boundary validation. */
    public function searchBalance(array $params, ?string $merchantAccountId = null, ?string $tenantId = null): array
    {
        // These arguments are trusted authentication context, not HTTP query fields.
        if ($merchantAccountId !== null && $tenantId !== null) {
            throw new InvalidArgumentException('资产报表身份范围冲突');
        }
        if ($tenantId !== null) {
            if (!ReportUserScope::positiveId($tenantId)) throw new InvalidArgumentException('登录白标身份无效');
            $params['source_type'] = 'tenant';
            $params['source_id'] = $tenantId;
            unset($params['source_keyword']);
        } elseif ($merchantAccountId !== null) {
            $params['source_type'] = 'merchant';
            $params['source_id'] = MerchantReportScope::forAccount($this->db, $merchantAccountId)->merchantId;
            unset($params['source_keyword']);
        }
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        if (!in_array($sort, self::BALANCE_SORTABLE, true)) {
            throw new InvalidArgumentException('不支持的排序字段');
        }
        $params['currency'] = strtoupper(trim((string) ($params['currency'] ?? ''))) ?: 'USDT';
        $rows = [];
        $ready = true;
        foreach (['funding', 'contract'] as $account) {
            $data = $this->buildRows(array_replace($params, ['account_type' => $account]), 'balance');
            $ready = $ready && $data['schema_ready'];
            foreach ($data['rows'] as $row) {
                $balance = array_intersect_key($row, array_flip(['id', 'stat_date', 'source_type', 'source_id',
                    'source_name', 'account_type', 'currency', 'funded_user_count', 'updated_at',
                    'closing_available', 'closing_occupied']));
                $balance['opening_total'] = $row['opening_assets'];
                $balance['closing_total'] = $row['closing_assets'];
                $balance['period_net_change'] = $row['opening_assets'] === null || $row['closing_assets'] === null
                    ? null : bcsub($row['closing_assets'], $row['opening_assets'], 16);
                $incomplete = $row['stat_status'] === 'incomplete' || $balance['opening_total'] === null
                    || $balance['closing_available'] === null || $balance['closing_occupied'] === null;
                $balance['stat_status'] = $incomplete ? 2 : ($row['stat_status'] === 'processing' ? 0 : 1);
                $rows[] = $balance;
            }
        }
        // Merge both accounts before ordering/pagination; never cap either account independently.
        return $this->paginate($rows, $params, $sort)
            + ['schema_ready' => $ready, 'currency' => 'USDT', 'currencies' => ['USDT'], 'user_types' => 'all'];
    }

    /**
     * Current account balances share report scope/version validation, but do not require
     * an opening boundary or flows. Return both accounts before filtering/pagination so
     * callers can calculate allocation against the entire source, not a visible page.
     */
    public function currentBalances(array $params): array
    {
        $date = CarbonImmutable::now('UTC')->toDateString();
        $rows = [];
        $ready = true;
        foreach (['funding', 'contract'] as $account) {
            $data = $this->buildRows(array_replace($params, [
                'period' => 'day', 'start_date' => $date, 'end_date' => $date,
                'account_type' => $account, 'currency' => 'USDT',
            ]), 'balance', true);
            $ready = $ready && $data['schema_ready'];
            array_push($rows, ...$data['rows']);
        }
        return ['rows' => $rows, 'schema_ready' => $ready, 'stat_date' => $date];
    }

    private function buildRows(array $params, string $report, bool $includeMissing = false): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $period = (string) ($params['period'] ?? 'day');
        $account = (string) ($params['account_type'] ?? 'funding');
        $type = (string) ($params['source_type'] ?? 'platform');
        $sourceId = isset($params['source_id']) ? (string) $params['source_id'] : null;
        if ($sourceId !== null && !(($type === 'platform' && $sourceId === '0')
            || ($type !== 'platform' && ReportUserScope::positiveId($sourceId)))) {
            throw new InvalidArgumentException('无效的来源ID');
        }
        if (!in_array($period, ['day', 'month'], true)
            || !in_array($account, ['funding', 'contract'], true) || !in_array($type, ['platform', 'tenant', 'merchant'], true)
            || ($params['currency'] ?? 'USDT') !== 'USDT') {
            throw new InvalidArgumentException('首期仅支持资金账户或合约账户的USDT日报/月报');
        }
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(29));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($start->greaterThan($end) || $end->greaterThan($today) || $start->diffInDays($end) > 365) {
            throw new InvalidArgumentException('统计范围最多366天且不能超过当前UTC日期');
        }
        if ($period === 'month') {
            $start = $start->startOfMonth();
            $end = $end->endOfMonth()->startOfDay()->min($today);
            if ($start->diffInDays($end) > 365) {
                throw new InvalidArgumentException('月报完整统计范围最多366天');
            }
        }
        $directory = (new SourceDirectory($this->db))->load();
        $sources = match ($type) {
            'merchant' => array_values($directory['agents']),
            'tenant' => array_values($directory['tenants']),
            default => [['source_type' => 'platform', 'source_id' => '0', 'source_name' => '6MM']],
        };
        if ($sourceId !== null) {
            $sources = array_values(array_filter($sources, static fn (array $source): bool => (string) $source['source_id'] === $sourceId));
        }
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if (mb_strlen($keyword) > 128) {
            throw new InvalidArgumentException('来源搜索最多128字符');
        }
        $prefix = $this->db->getDriverName() === 'pgsql' ? 'public.' : '';
        $table = SourceAssetReportSchema::TABLE;
        $columns = $this->db->getDriverName() === 'pgsql'
            ? $this->db->table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->pluck('column_name')->all()
            : ($this->db->getSchemaBuilder()->hasTable($table) ? $this->db->getSchemaBuilder()->getColumnListing($table) : []);
        $ready = count(array_intersect(['asset_report_opening', 'asset_report_closing', 'asset_report_flows'], $columns)) === 3;
        $facts = [];
        if ($ready) {
            foreach ($this->db->table($prefix . $table)->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()])
                ->where('currency', 'USDT')->where('account_type', $account)->where('source_type', $type)
                ->when($sourceId !== null, static fn ($query) => $query->where('source_id', $sourceId))
                ->get(['stat_date', 'source_type', 'source_id', 'source_name', 'asset_report_opening', 'asset_report_closing', 'asset_report_flows']) as $record) {
                $facts[substr((string) $record->stat_date, 0, 10) . ':' . $record->source_type . ':' . $record->source_id] = $record;
            }
        }
        $known = [];
        foreach ($sources as $source) $known[($source['source_type'] . ':' . $source['source_id'])] = $source;
        foreach ($facts as $record) $known[$record->source_type . ':' . $record->source_id] ??= [
            'source_type' => $record->source_type, 'source_id' => (string) $record->source_id, 'source_name' => $record->source_name,
        ];
        $sources = array_values($known);
        $sources = array_values(array_filter($sources, static fn (array $source): bool => $keyword === ''
            || mb_stripos($source['source_name'], $keyword) !== false
            || (ctype_digit($keyword) && $source['source_id'] === (ltrim($keyword, '0') ?: '0'))));
        $rows = [];
        foreach ($sources as $source) {
            $members = [($source['source_type'] . ':' . $source['source_id'])];
            $days = [];
            $recordDates = [];
            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                // Facts are already scoped by account and currency in SQL; include the
                // exact date/source identity, not another source's record on that date.
                if (isset($facts[$day->toDateString() . ':' . $members[0]])) {
                    $recordDates[$day->toDateString()] = true;
                }
                $days[] = $this->day($day, $today, $source, $members, $facts, $account, $report);
            }
            if ($period === 'day') {
                foreach ($days as $day) {
                    if ($includeMissing || isset($recordDates[$day['stat_date']])) $rows[] = $day;
                }
            } else {
                $months = [];
                foreach ($days as $day) {
                    $months[substr($day['stat_date'], 0, 7)][] = $day;
                }
                foreach ($months as $month => $monthDays) {
                    if (!$includeMissing && !array_filter($monthDays, static fn (array $day): bool => isset($recordDates[$day['stat_date']]))) {
                        continue;
                    }
                    // Keep calendar boundaries/gaps for monthly calculations. Hiding an
                    // absent month must not replace its missing boundaries with mid-month snapshots.
                    $row = end($monthDays);
                    $row['stat_date'] = $month;
                    $row['opening_assets'] = $monthDays[0]['opening_assets'];
                    foreach (self::FLOWS as $field) {
                        $row[$field] = '0';
                        foreach ($monthDays as $item) {
                            $row[$field] = $this->sum($row[$field], $item[$field]);
                        }
                    }
                    if ($report === 'changes' && count(array_filter($monthDays, static fn (array $d): bool => $d['stat_status'] === 'incomplete')) > 0) {
                        $row['stat_status'] = 'incomplete';
                    }
                    $rows[] = $row;
                }
            }
        }
        foreach ($rows as &$row) {
            $row['id'] = implode(':', [$report, $period, $row['stat_date'], $row['source_type'], $row['source_id'], $account]);
            foreach ($row['buckets'] as $i => &$bucket) {
                $bucket['user_share'] = $this->ratio($bucket['count'], $row['funded_user_count']);
                $bucket['asset_share'] = $this->ratio($bucket['amount'], $row['positive_assets']);
                $row['bucket_' . $i . '_amount'] = $bucket['amount'];
            }
            unset($bucket);
        }
        unset($row);
        return ['rows' => $rows, 'schema_ready' => $ready];
    }

    private function paginate(array $rows, array $params, string $sort): array
    {
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $left = $a[$sort];
            $right = $b[$sort];
            if ($left === null || $right === null) {
                return $left === $right ? strcmp($a['id'], $b['id']) : ($left === null ? 1 : -1);
            }
            $result = is_numeric($left) && is_numeric($right) ? bccomp((string) $left, (string) $right, 16) : strcmp((string) $left, (string) $right);
            return $result * $direction ?: strcmp($a['id'], $b['id']);
        });
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size), 'count' => count($rows),
            'page' => $page, 'limit' => $size];
    }

    private function day(CarbonImmutable $date, CarbonImmutable $today, array $source, array $members, array $facts, string $account, string $report): array
    {
        $row = ['stat_date' => $date->toDateString(), 'source_type' => $source['source_type'],
            'source_id' => (string) $source['source_id'], 'source_name' => $source['source_name'],
            'account_type' => $account, 'currency' => 'USDT', 'opening_assets' => '0', 'closing_assets' => '0',
            'total_assets' => '0', 'positive_assets' => '0', 'negative_assets' => '0', 'funded_user_count' => 0,
            'buckets' => array_fill(0, 5, ['count' => 0, 'amount' => '0']),
            'stat_status' => $date->isSameDay($today) ? 'processing' : 'completed', 'updated_at' => null]
            + array_fill_keys(self::FLOWS, '0');
        if ($report === 'balance') {
            $row['closing_available'] = '0';
            $row['closing_occupied'] = '0';
        }
        foreach ($members as $sourceKey) {
            $record = $facts[$date->toDateString() . ':' . $sourceKey] ?? null;
            $open = $this->snapshot($record->asset_report_opening ?? null);
            $close = $this->snapshot($record->asset_report_closing ?? null);
            if ($open !== null && (!($open['finalized'] ?? false) || !$this->atBoundary($open['captured_at'], $date))) {
                $open = null;
            }
            if ($close !== null) {
                $stamp = CarbonImmutable::parse($close['captured_at'])->utc();
                if ($date->isSameDay($today)) {
                    if (!$stamp->isSameDay($date)) {
                        $close = null;
                    }
                } elseif (!($close['finalized'] ?? false) || !$this->atBoundary($close['captured_at'], $date->addDay())) {
                    $close = null;
                }
            }
            $flow = $this->payload($record->asset_report_flows ?? null);
            if ($flow !== null) {
                foreach (self::FLOWS as $field) {
                    if (!$this->decimal($flow[$field] ?? null)) {
                        $flow = null;
                        break;
                    }
                }
                if ($flow !== null && (!is_int($flow['unclassified_count'] ?? null) || $flow['unclassified_count'] < 0
                    || bccomp($flow['deposit_in'], '0', 16) < 0 || bccomp($flow['withdraw_out'], '0', 16) < 0)) {
                    $flow = null;
                }
            }
            // An absent boundary is unknown, regardless of the source's creation date.
            $openingTotal = $open['total'] ?? null;
            $row['opening_assets'] = $this->sum($row['opening_assets'], $openingTotal);
            foreach (['closing_assets' => 'total', 'total_assets' => 'total', 'positive_assets' => 'positive_total', 'negative_assets' => 'negative_total'] as $field => $key) {
                $row[$field] = $this->sum($row[$field], $close[$key] ?? null);
            }
            if ($report === 'balance') {
                // Older distribution snapshots remain usable for totals/counts, but not for missing breakdowns.
                $hasBreakdown = $close !== null && $this->decimal($close['available'] ?? null)
                    && $this->decimal($close['occupied'] ?? null) && bccomp($close['occupied'], '0', 16) >= 0
                    && bccomp(bcadd($close['available'], $close['occupied'], 16), $close['total'], 16) === 0;
                $row['closing_available'] = $this->sum($row['closing_available'], $hasBreakdown ? $close['available'] : null);
                $row['closing_occupied'] = $this->sum($row['closing_occupied'], $hasBreakdown ? $close['occupied'] : null);
            }
            $row['funded_user_count'] = $row['funded_user_count'] === null || $close === null
                ? null : $row['funded_user_count'] + $close['funded_user_count'];
            foreach ($row['buckets'] as $i => &$bucket) {
                $bucket['count'] = $bucket['count'] === null || $close === null ? null : $bucket['count'] + $close['buckets'][$i]['count'];
                $bucket['amount'] = $this->sum($bucket['amount'], $close['buckets'][$i]['amount'] ?? null);
            }
            unset($bucket);
            foreach (self::FLOWS as $field) {
                $row[$field] = $this->sum($row[$field], $flow[$field] ?? null);
            }
            $flowsClosed = $flow !== null && ($date->isSameDay($today)
                || CarbonImmutable::parse($flow['captured_at'])->greaterThanOrEqualTo($date->addDay()));
            if ($close === null || ($report === 'changes' && ($openingTotal === null || !$flowsClosed || ($flow['unclassified_count'] ?? 0) > 0))) {
                $row['stat_status'] = 'incomplete';
            }
            $stamp = $close['captured_at'] ?? null;
            if ($stamp !== null && ($row['updated_at'] === null || strcmp($stamp, $row['updated_at']) < 0)) {
                $row['updated_at'] = $stamp;
            }
        }
        // Funding has no contract PnL/fees. Null means inapplicable, not an invented zero.
        if ($account === 'funding') {
            $row['realized_pnl'] = null;
            $row['fee_net'] = null;
        }
        return $row;
    }

    private function payload(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($value) || ($value['version'] ?? null) !== SourceAssetReportSchema::VERSION || ($value['user_types'] ?? null) !== 'all'
            || !is_string($value['captured_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value['captured_at']) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value['captured_at'])->greaterThan(CarbonImmutable::now('UTC')) ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }

    private function snapshot(?string $json): ?array
    {
        $value = $this->payload($json);
        if ($value === null || !is_bool($value['finalized'] ?? null)
            || !is_int($value['funded_user_count'] ?? null) || $value['funded_user_count'] < 0
            || !is_array($value['buckets'] ?? null) || count($value['buckets']) !== 5) {
            return null;
        }
        foreach (['total', 'positive_total', 'negative_total'] as $key) {
            if (!$this->decimal($value[$key] ?? null)) {
                return null;
            }
        }
        $count = 0;
        $amount = '0';
        foreach ($value['buckets'] as $bucket) {
            if (!is_array($bucket) || !is_int($bucket['count'] ?? null) || $bucket['count'] < 0
                || !$this->decimal($bucket['amount'] ?? null) || bccomp($bucket['amount'], '0', 16) < 0) {
                return null;
            }
            $count += $bucket['count'];
            $amount = bcadd($amount, $bucket['amount'], 16);
        }
        return $count === $value['funded_user_count'] && bccomp($amount, $value['positive_total'], 16) === 0
            && bccomp($value['positive_total'], '0', 16) >= 0 && bccomp($value['negative_total'], '0', 16) <= 0
            && bccomp(bcadd($value['positive_total'], $value['negative_total'], 16), $value['total'], 16) === 0 ? $value : null;
    }

    private function atBoundary(string $stamp, CarbonImmutable $date): bool
    {
        $at = CarbonImmutable::parse($stamp)->utc();
        return $at->isSameDay($date) && $at->format('H:i') === '00:00';
    }

    private function decimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/^-?\d+(?:\.\d{1,16})?$/D', $value) === 1;
    }

    private function sum(?string $left, ?string $right): ?string
    {
        return $left === null || $right === null ? null : bcadd($left, $right, 16);
    }

    private function ratio(mixed $numerator, mixed $denominator): ?string
    {
        if ($numerator === null || $denominator === null) {
            return null;
        }
        return bccomp((string) $denominator, '0', 16) === 0 ? '0.0000000000000000'
            : bcdiv(bcmul((string) $numerator, '100', 16), (string) $denominator, 16);
    }

    private function date(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($value === '') {
            return $fallback;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            $date = false;
        }
        if (!$date || $date->toDateString() !== $value) {
            throw new InvalidArgumentException('日期格式必须为YYYY-MM-DD');
        }
        return $date;
    }
}
