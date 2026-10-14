<?php

declare(strict_types=1);

namespace SixMm\Shared\PredictionReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\MerchantReportScope;

final class PredictionUserPnlStore
{
    private const COUNTS = ['trading_user_count', 'valid_trading_user_count', 'profitable_user_count',
        'losing_user_count', 'breakeven_user_count', 'order_count', 'win_order_count',
        'lose_order_count', 'refund_order_count'];
    private const AMOUNTS = ['stake_amount', 'valid_stake_amount', 'return_amount', 'user_net_pnl',
        'platform_pnl', 'payout_rate'];

    public function __construct(private ConnectionInterface $database) {}

    private function table(): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . PredictionUserPnlSchema::TABLE;
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

    public function search(array $params, ?string $merchantAccountId = null): array
    {
        $scope = $merchantAccountId === null ? null : MerchantReportScope::forAccount($this->database, $merchantAccountId);
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(89));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $play = (string) ($params['play_type'] ?? 'all');
        if (!in_array($play, ['all', 'grid', 'high_low', 'up_down'], true)) throw new InvalidArgumentException('无效的预测玩法');
        $symbol = strtoupper(trim((string) ($params['symbol'] ?? '')));
        if ($symbol === '') $symbol = '*';
        if (strlen($symbol) > 40 || !preg_match('/^(?:\*|[A-Z0-9_:\/-]+)$/D', $symbol)) throw new InvalidArgumentException('合约格式不正确');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        $allowed = array_merge(['stat_date', 'source_id', 'source_code', 'source_name', 'source_type', 'stat_status'], self::COUNTS, self::AMOUNTS);
        if (!in_array($sort, $allowed, true)) throw new InvalidArgumentException('不支持的排序字段');
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));
        return $this->database->transaction(function () use ($params, $scope, $start, $end, $play, $symbol, $sort, $direction, $page, $size): array {
            if ($this->database->getDriverName() === 'pgsql' && $this->database->transactionLevel() === 1) {
                $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $query = $this->database->table($this->table())->where('play_type', $play)->where('symbol', $symbol)
                ->whereBetween('stat_date', [$start->toDateString(), $end->toDateString()]);
            $this->applyScope($query, $params, $scope);
            $count = (clone $query)->count();
            $rows = $query->orderBy($sort === 'stat_status' ? 'status' : $sort, $direction)
                ->when($sort !== 'stat_date', static fn (Builder $q) => $q->orderBy('stat_date', 'desc'))
                ->orderBy('source_type')->orderBy('source_id')
                ->offset(($page - 1) * $size)->limit($size)->get();
            return ['lists' => $rows->map(static function ($record): array {
                $row = (array) $record;
                $row['source_id'] = (string) $row['source_id'];
                $row['symbol'] = $row['symbol'] === '*' ? null : $row['symbol'];
                $row['id'] = implode(':', [$row['stat_date'], $row['source_type'], $row['source_id'], $row['play_type'], $row['symbol'] ?? '*']);
                $row['stat_status'] = (int) $row['status'];
                foreach (self::COUNTS as $field) $row[$field] = (int) $row[$field];
                foreach (self::AMOUNTS as $field) $row[$field] = (string) $row[$field];
                unset($row['status'], $row['updated_at']);
                return $row;
            })->all(), 'count' => (int) $count, 'page' => $page, 'limit' => $size];
        });
    }

    /** @return string[] */
    public function symbols(?string $merchantAccountId = null): array
    {
        $scope = $merchantAccountId === null ? null : MerchantReportScope::forAccount($this->database, $merchantAccountId);
        $query = $this->database->table($this->table())->where('symbol', '<>', '*');
        if ($scope !== null) $query->where('source_type', 'merchant')->where('source_id', $scope->merchantId);
        return array_values(array_map('strval', $query->distinct()->orderBy('symbol')->pluck('symbol')->all()));
    }

    private function applyScope(Builder $query, array $params, ?MerchantReportScope $scope): void
    {
        if ($scope !== null) {
            $query->where('source_type', 'merchant')->where('source_id', $scope->merchantId);
            return;
        }
        $type = (string) ($params['source_type'] ?? '');
        if ($type !== '' && !in_array($type, ['merchant', 'tenant', 'platform'], true)) throw new InvalidArgumentException('无效的来源类型');
        $query->whereIn('source_type', $type === '' ? ['merchant', 'tenant'] : [$type]);
        $id = (string) ($params['source_id'] ?? '');
        if ($id !== '') {
            if (!preg_match('/^\d+$/D', $id)) throw new InvalidArgumentException('无效的来源ID');
            $query->where('source_id', $id);
        }
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if ($keyword !== '') {
            if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
            $pattern = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $numericId = ctype_digit($keyword) ? (ltrim($keyword, '0') ?: '0') : null;
            if ($numericId !== null && (strlen($numericId) > 19 || (strlen($numericId) === 19 && strcmp($numericId, '9223372036854775807') > 0))) $numericId = null;
            $query->where(static function (Builder $q) use ($pattern, $numericId): void {
                $q->whereRaw("LOWER(source_name) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
                if ($numericId !== null) $q->orWhere('source_id', $numericId);
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
