<?php

namespace SixMm\Shared\MarketData;

use Carbon\CarbonImmutable;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

class MarketHistory
{
    public const METRICS = ['last_price', 'mark_price', 'index_price', 'value', 'open_interest'];
    public const INTERVALS = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '12h', '1d', '1w', '1mon'];
    public const RANGES = ['24h', '7d', '30d'];

    private const PRICE_VIEWS = ['last_price' => 'market', 'mark_price' => 'mark', 'index_price' => 'index'];
    private const INTERVAL_SECONDS = [
        '1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800,
        '1h' => 3600, '2h' => 7200, '4h' => 14400, '6h' => 21600, '12h' => 43200,
        '1d' => 86400, '1w' => 604800, '1mon' => 2678400,
    ];
    private const RANGE_SECONDS = ['24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function __construct(private \Closure $connections, private \Closure $symbolOrder) {}

    /** null is the platform scope; an empty array explicitly denies all symbols. */
    public function lists(array $params, ?array $allowedSymbols = null): array
    {
        $metric = $params['metric'] ?? 'last_price';
        $interval = $params['interval'] ?? '1m';
        $range = $params['range'] ?? '24h';
        if (!in_array($metric, self::METRICS, true) || !in_array($interval, self::INTERVALS, true)
            || !in_array($range, self::RANGES, true)) {
            throw new InvalidArgumentException('不支持的行情指标、周期或时间范围');
        }

        $symbol = strtoupper(trim((string) ($params['symbol'] ?? '')));
        $pageNo = max(1, min(2147483647, (int) ($params['page_no'] ?? 1)));
        $pageSize = max(1, min(40, (int) ($params['page_size'] ?? 20)));
        $now = CarbonImmutable::now('UTC')->startOfMinute();
        $end = isset($params['end_ms'])
            ? CarbonImmutable::createFromTimestampMs(max(0, min((int) $params['end_ms'], $now->getTimestamp() * 1000)), 'UTC')->startOfMinute()
            : $now;
        $start = $end->subSeconds(self::RANGE_SECONDS[$range]);
        $isPrice = isset(self::PRICE_VIEWS[$metric]);
        if ($allowedSymbols !== null && !$isPrice) {
            throw new InvalidArgumentException('站点行情仅支持价格K线');
        }
        if ($allowedSymbols !== null) {
            $allowedSymbols = array_values(array_unique(array_map('strval', $allowedSymbols)));
            if ($allowedSymbols === [] || ($symbol !== '' && !in_array($symbol, $allowedSymbols, true))) {
                return ['lists' => [], 'count' => 0, 'metric' => $metric, 'interval' => $interval,
                    'range' => $range, 'source' => 'market_kline', 'page_no' => $pageNo,
                    'page_size' => $pageSize, 'end_ms' => $end->getTimestamp() * 1000];
            }
        }
        $orderedSymbols = $isPrice && $symbol === ''
            ? ($allowedSymbols ?? ($this->symbolOrder)()) : [];
        $connection = ($this->connections)($isPrice ? 'pg' : null);

        $total = null;
        $rows = $connection->transaction(function () use ($connection, $metric, $interval, $symbol, $start, $end, $isPrice, $orderedSymbols, $pageNo, $pageSize, $allowedSymbols, &$total) {
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->statement("SET LOCAL statement_timeout = '5s'");
            if ($isPrice) {
                // 表名只由服务端白名单构造，不能由请求直接指定。
                $view = 'public.kline_' . self::PRICE_VIEWS[$metric] . '_' . $interval;
                $from = "FROM {$view} WHERE bucket >= ? AND bucket < ?";
                $bindings = [$start->toIso8601String(), $end->toIso8601String()];
                if ($symbol !== '') {
                    $from .= ' AND symbol = ?';
                    $bindings[] = $symbol;
                }
                if ($allowedSymbols !== null) {
                    $from .= ' AND symbol IN (' . implode(',', array_fill(0, count($allowedSymbols), '?')) . ')';
                    array_push($bindings, ...$allowedSymbols);
                }
                $total = (int) $connection->selectOne("SELECT count(*) AS total {$from}", $bindings)->total;
                $order = 'bucket DESC, symbol ASC';
                if ($symbol === '') {
                    // 合约配置与行情可能不在同一数据库，绑定排序目录，并在 LIMIT 前排序。
                    $order = 'bucket DESC, array_position(ARRAY(SELECT jsonb_array_elements_text(?::jsonb)), symbol) ASC NULLS LAST, symbol ASC';
                    $bindings[] = json_encode($orderedSymbols, JSON_THROW_ON_ERROR);
                }
                $bindings[] = $pageSize;
                $bindings[] = ($pageNo - 1) * $pageSize;
                return $connection->select("SELECT bucket, symbol, open::text, high::text, low::text, close::text
                    {$from} ORDER BY {$order} LIMIT ? OFFSET ?", $bindings);
            }

            // 两个统计指标是滚动快照，绝不能 SUM(value/open_interest)。
            // 先限制聚合窗口；数据稀疏且不足20条时才扩至用户所选完整范围。
            // 全部合约通常一个时间桶就超过20条；单合约才需要约20个时间桶。
            $windowBuckets = $symbol === '' ? 2 : 22;
            $queryStart = $interval === '1m' ? $start : $end->subSeconds(self::INTERVAL_SECONDS[$interval] * $windowBuckets)->max($start);
            $rows = $this->snapshots($connection, $metric, $interval, $symbol, $queryStart, $end);
            if ($interval !== '1m' && count($rows) < 20 && $queryStart->greaterThan($start)) {
                $rows = $this->snapshots($connection, $metric, $interval, $symbol, $start, $end);
            }
            return $rows;
        });

        return [
            'lists' => array_map(function ($row): array {
                $row = (array) $row;
                return [
                    'timestamp_ms' => CarbonImmutable::parse($row['bucket'], 'UTC')->getTimestamp() * 1000,
                    'symbol' => $row['symbol'],
                    'open' => $this->decimal($row['open'] ?? null),
                    'high' => $this->decimal($row['high'] ?? null),
                    'low' => $this->decimal($row['low'] ?? null),
                    'close' => $this->decimal($row['close'] ?? null),
                ];
            }, $rows),
            'count' => $total ?? count($rows),
            'metric' => $metric,
            'interval' => $interval,
            'range' => $range,
            'source' => $isPrice ? 'market_kline' : 'minute_snapshot',
            ...($isPrice ? ['page_no' => $pageNo, 'page_size' => $pageSize, 'end_ms' => $end->getTimestamp() * 1000] : []),
        ];
    }

    private function snapshots($connection, string $metric, string $interval, string $symbol, CarbonImmutable $start, CarbonImmutable $end): array
    {
        // minute 是现有采集程序按 UTC 写入的 YYYY-MM-DD HH:mm 字符串。
        // WHERE 保持原字符串比较，以便复用 (minute, symbol) 索引。
        $where = "minute >= ? AND minute < ? AND deleted_at IS NULL AND {$metric} IS NOT NULL";
        $bindings = [$start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')];
        if ($symbol !== '') {
            $where .= ' AND symbol = ?';
            $bindings[] = $symbol;
        }
        if ($interval === '1m') {
            return $connection->select("SELECT minute::timestamp AT TIME ZONE 'UTC' AS bucket, symbol,
                {$metric}::text AS open, {$metric}::text AS high, {$metric}::text AS low, {$metric}::text AS close
                FROM funding_rate_other WHERE {$where} ORDER BY minute DESC, symbol ASC LIMIT 20", $bindings);
        }
        if ($symbol === '' && in_array($interval, ['1w', '1mon'], true)) {
            return $this->largeSnapshotBuckets($connection, $metric, $interval, $start, $end);
        }

        if ($interval === '1w' || $interval === '1mon') {
            $unit = $interval === '1w' ? 'week' : 'month';
            $bucket = "date_trunc('{$unit}', minute::timestamp) AT TIME ZONE 'UTC'";
        } else {
            $seconds = self::INTERVAL_SECONDS[$interval];
            $bucket = "to_timestamp(floor(extract(epoch FROM minute::timestamp AT TIME ZONE 'UTC') / {$seconds}) * {$seconds})";
        }
        $bindings[] = $start->toIso8601String();
        $bindings[] = $end->toIso8601String();
        return $connection->select("SELECT {$bucket} AS bucket, symbol,
            (array_agg({$metric} ORDER BY minute ASC))[1]::text AS open,
            max({$metric})::text AS high, min({$metric})::text AS low,
            (array_agg({$metric} ORDER BY minute DESC))[1]::text AS close
            FROM funding_rate_other WHERE {$where}
            GROUP BY bucket, symbol HAVING {$bucket} >= ? AND {$bucket} < ?
            ORDER BY bucket DESC, symbol ASC LIMIT 20", $bindings);
    }

    private function largeSnapshotBuckets($connection, string $metric, string $interval, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [];
        $cursor = $end;
        while (count($rows) < 20 && $cursor->greaterThan($start)) {
            // 用时间索引找到最近有数据的桶，跳过空周期，不逐分钟计算桶表达式。
            $latest = $connection->selectOne("SELECT minute FROM funding_rate_other
                WHERE minute >= ? AND minute < ? AND deleted_at IS NULL AND {$metric} IS NOT NULL
                ORDER BY minute DESC LIMIT 1", [$start->format('Y-m-d H:i'), $cursor->format('Y-m-d H:i')]);
            if (!$latest) break;
            $latestTime = CarbonImmutable::parse($latest->minute, 'UTC');
            $bucket = $interval === '1w' ? $latestTime->startOfWeek(CarbonImmutable::MONDAY) : $latestTime->startOfMonth();
            if ($bucket->lessThan($start)) break;

            $remaining = 20 - count($rows);
            $bucketStart = $bucket->format('Y-m-d H:i');
            $bucketEnd = $cursor->format('Y-m-d H:i');
            $symbols = $connection->select("SELECT DISTINCT symbol FROM funding_rate_other
                WHERE minute >= ? AND minute < ? AND deleted_at IS NULL AND {$metric} IS NOT NULL
                ORDER BY symbol ASC LIMIT {$remaining}", [$bucketStart, $bucketEnd]);
            if (!$symbols) break;
            $symbolValues = array_map(fn ($row) => $row->symbol, $symbols);
            $placeholders = implode(',', array_fill(0, count($symbolValues), '?'));
            // 最大/最小值可并行聚合；首尾值用现有 (minute, symbol) 索引取一条，
            // 不再为整月分钟快照建立两个有序数组。
            $bucketRows = $connection->select("SELECT ?::timestamptz AS bucket, totals.symbol, totals.high, totals.low,
                (SELECT e.{$metric}::text FROM funding_rate_other e
                    WHERE e.minute >= ? AND e.minute < ? AND e.symbol = totals.symbol
                        AND e.deleted_at IS NULL AND e.{$metric} IS NOT NULL
                    ORDER BY e.minute ASC LIMIT 1) AS open,
                (SELECT e.{$metric}::text FROM funding_rate_other e
                    WHERE e.minute >= ? AND e.minute < ? AND e.symbol = totals.symbol
                        AND e.deleted_at IS NULL AND e.{$metric} IS NOT NULL
                    ORDER BY e.minute DESC LIMIT 1) AS close
                FROM (SELECT symbol, max({$metric})::text AS high, min({$metric})::text AS low
                    FROM funding_rate_other
                    WHERE minute >= ? AND minute < ? AND deleted_at IS NULL AND {$metric} IS NOT NULL
                        AND symbol IN ({$placeholders})
                    GROUP BY symbol) totals ORDER BY totals.symbol ASC", [
                    $bucket->toIso8601String(), $bucketStart, $bucketEnd, $bucketStart, $bucketEnd,
                    $bucketStart, $bucketEnd, ...$symbolValues,
                ]);
            array_push($rows, ...$bucketRows);
            $cursor = $bucket;
        }
        return $rows;
    }

    private function decimal($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        if (stripos($value, 'e') !== false) {
            $value = (string) BigDecimal::of($value);
        }
        if (strpos($value, '.') !== false && stripos($value, 'e') === false) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '-0' ? '0' : $value;
    }
}
