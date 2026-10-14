<?php

declare(strict_types=1);

namespace SixMm\Shared\UserReports;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use SixMm\Shared\FuturesReports\ReportUserScope;
use SixMm\Shared\FuturesReports\SourceDirectory;

/** Read model for the existing merchant-produced user daily facts. */
final class UserDailyReportStore
{
    private const COUNT_FIELDS = [
        'initial_user_count', 'new_user_count', 'transfer_in_user_count', 'transfer_out_user_count',
        'active_user_count', 'trading_user_count', 'prediction_trading_user_count',
        'total_trading_user_count', 'contract_trade_count', 'prediction_valid_order_count',
    ];
    private const AMOUNT_FIELDS = [
        'transfer_in_amount', 'transfer_out_amount', 'contract_trade_amount', 'contract_trade_pnl',
        'contract_trading_fee_income', 'contract_liquidation_fee_income', 'prediction_valid_stake_amount',
    ];
    private const SORTABLE = [
        'stat_date', 'source_code', 'source_name', 'source_type', 'initial_user_count', 'new_user_count',
        'transfer_in_user_count', 'transfer_in_conversion_rate', 'transfer_in_amount',
        'transfer_out_user_count', 'transfer_out_amount', 'active_user_count', 'active_rate',
        'trading_user_count', 'prediction_trading_user_count', 'prediction_valid_order_count',
        'prediction_valid_stake_amount', 'total_trading_user_count', 'contract_trade_count',
        'contract_trade_amount', 'contract_trade_pnl', 'contract_trading_fee_income',
        'contract_liquidation_fee_income', 'stat_status',
    ];

    public function __construct(private ConnectionInterface $database) {}

    /**
     * The optional merchant ID must come from the authenticated root merchant, never request parameters.
     * Platform and tenant rows are merchant cohorts; direct users are not in the source fact table.
     *
     * @return array{lists: array<int, array<string, mixed>>, count: int, page: int, limit: int}
     */
    public function search(array $params, ?string $merchantId = null): array
    {
        $period = (string) ($params['period'] ?? 'day');
        if (!in_array($period, ['day', 'month'], true)) throw new InvalidArgumentException('无效的统计周期');
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $this->date((string) ($params['start_date'] ?? ''), $today->subDays(365));
        $end = $this->date((string) ($params['end_date'] ?? ''), $today);
        if ($end->lessThan($start) || $start->diffInDays($end) > 365) throw new InvalidArgumentException('统计范围最多366天');
        $userType = trim((string) ($params['user_type'] ?? ''));
        if ($userType !== '' && !in_array($userType, ['1', '2'], true)) throw new InvalidArgumentException('无效的用户类型');
        $sort = (string) ($params['order_by'] ?? 'stat_date');
        if (!in_array($sort, self::SORTABLE, true)) throw new InvalidArgumentException('不支持的排序字段');
        $direction = ($params['order_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($params['page_no'] ?? 1));
        $size = min(100, max(1, (int) ($params['page_size'] ?? 20)));

        $directory = $merchantId === null ? (new SourceDirectory($this->database))->load() : [];
        $sources = $this->sources($params, $merchantId, $directory);
        $agentSources = $this->agentSources($sources, $merchantId, $directory);
        $tenantSources = array_values(array_filter($sources,
            static fn (array $source): bool => $source['source_type'] === 'tenant'));
        if ($agentSources === [] && $tenantSources === []) {
            return ['lists' => [], 'count' => 0, 'page' => $page, 'limit' => $size];
        }

        $dates = [$start->toDateString(), $end->toDateString()];
        $ids = array_keys($agentSources);
        $facts = $this->database->table($this->table('admin_agent_user_daily_statistics'))
            ->whereIn('agent_id', $ids)->whereBetween('stat_date', $dates)
            ->whereIn('user_type', $userType === '' ? [1, 2] : [(int) $userType])
            ->orderBy('stat_date')->get();
        $predictions = [];
        foreach ($this->database->table($this->table('admin_agent_prediction_user_pnl_daily_statistics'))
            ->whereIn('agent_id', $ids)->whereBetween('stat_date', $dates)
            ->where('play_type', 'all')->where('symbol', '*')->where('settle_asset', 'USDT')
            ->get(['agent_id', 'stat_date', 'win_order_count', 'lose_order_count', 'valid_stake_amount']) as $prediction) {
            $key = $prediction->agent_id . ':' . substr((string) $prediction->stat_date, 0, 10);
            $predictions[$key] ??= ['count' => 0, 'amount' => '0'];
            $predictions[$key]['count'] += (int) $prediction->win_order_count + (int) $prediction->lose_order_count;
            $predictions[$key]['amount'] = bcadd($predictions[$key]['amount'], (string) $prediction->valid_stake_amount, 16);
        }

        $agentDays = [];
        foreach ($facts as $record) {
            $fact = (array) $record;
            $id = (string) $fact['agent_id'];
            $date = substr((string) $fact['stat_date'], 0, 10);
            $key = $id . ':' . $date;
            $agentDays[$key] ??= $this->emptyMetrics();
            $agentDays[$key]['agent_id'] = $id;
            $agentDays[$key]['stat_date'] = $date;
            $this->addFact($agentDays[$key], $fact);
        }
        $daily = [];
        foreach ($agentDays as $key => $fact) {
            $fact['prediction_valid_order_count'] = $predictions[$key]['count'] ?? 0;
            $fact['prediction_valid_stake_amount'] = $predictions[$key]['amount'] ?? '0';
            foreach ($agentSources[$fact['agent_id']] as $source) {
                $groupKey = implode(':', [$fact['stat_date'], $source['source_type'], $source['source_id']]);
                $daily[$groupKey] ??= $this->emptyRow($fact['stat_date'], $source);
                $this->addFact($daily[$groupKey], $fact);
                $daily[$groupKey]['__baseline_by_agent'][$fact['agent_id']] = $fact['initial_user_count'];
            }
        }
        // The agent job produces facts only for merchants. Keep every white label visible
        // on dates when that job produced a report, even if it has no merchant facts.
        if ($tenantSources !== []) {
            $reportDates = $this->database->table($this->table('admin_agent_user_daily_statistics'))
                ->whereBetween('stat_date', $dates)->distinct()->orderBy('stat_date')->pluck('stat_date');
            foreach ($reportDates as $reportDate) {
                $date = substr((string) $reportDate, 0, 10);
                foreach ($tenantSources as $source) {
                    $groupKey = implode(':', [$date, $source['source_type'], $source['source_id']]);
                    $daily[$groupKey] ??= $this->emptyRow($date, $source);
                }
            }
        }
        $groups = $period === 'day' ? array_values($daily) : $this->monthly(array_values($daily));
        $rows = array_map(fn (array $row): array => $this->responseRow($row, $period, $today), $groups);
        usort($rows, static function (array $left, array $right) use ($sort, $direction): int {
            $a = (string) $left[$sort]; $b = (string) $right[$sort];
            $cmp = is_numeric($a) && is_numeric($b) ? bccomp($a, $b, 16) : strcmp($a, $b);
            return ($direction === 'asc' ? $cmp : -$cmp) ?: strcmp($left['id'], $right['id']);
        });
        return ['lists' => array_slice($rows, ($page - 1) * $size, $size),
            'count' => count($rows), 'page' => $page, 'limit' => $size];
    }

    private function table(string $name): string
    {
        return ($this->database->getDriverName() === 'pgsql' ? 'public.' : '') . $name;
    }

    private function sources(array $params, ?string $merchantId, array $directory): array
    {
        if ($merchantId !== null) {
            if (!ReportUserScope::positiveId($merchantId)) throw new InvalidArgumentException('登录商户身份无效');
            return [['source_type' => 'merchant', 'source_id' => $merchantId,
                'source_code' => $merchantId, 'source_name' => $merchantId]];
        }
        $type = (string) ($params['source_type'] ?? '');
        if ($type !== '' && !in_array($type, ['platform', 'tenant', 'merchant'], true)) throw new InvalidArgumentException('无效的来源类型');
        $id = trim((string) ($params['source_id'] ?? ''));
        if ($id !== '' && !preg_match('/^\d+$/D', $id)) throw new InvalidArgumentException('无效的来源ID');
        $keyword = trim((string) ($params['source_keyword'] ?? ''));
        if (mb_strlen($keyword, 'UTF-8') > 128) throw new InvalidArgumentException('来源搜索内容最多128个字符');
        $platform = ['source_type' => 'platform', 'source_id' => '0', 'source_code' => '6MM', 'source_name' => '6MM'];
        $all = array_merge(array_values($directory['agents']), array_values($directory['tenants']), [$platform]);
        return array_values(array_filter($all, static function (array $source) use ($type, $id, $keyword): bool {
            if ($type !== '' && $source['source_type'] !== $type) return false;
            if ($id !== '' && $source['source_id'] !== (ltrim($id, '0') ?: '0')) return false;
            return $keyword === '' || mb_stripos($source['source_name'], $keyword, 0, 'UTF-8') !== false
                || (ctype_digit($keyword) && $source['source_id'] === (ltrim($keyword, '0') ?: '0'));
        }));
    }

    private function agentSources(array $sources, ?string $merchantId, array $directory): array
    {
        if ($merchantId !== null) return [$merchantId => $sources];
        $selected = [];
        foreach ($sources as $source) $selected[$source['source_type'] . ':' . $source['source_id']] = $source;
        $result = [];
        foreach ($directory['agents'] as $id => $merchant) {
            $tenantId = $directory['sites'][$merchant['site_id']] ?? null;
            foreach (['merchant:' . $id, 'tenant:' . $tenantId, 'platform:0'] as $key) {
                if (isset($selected[$key])) $result[(string) $id][] = $selected[$key];
            }
        }
        return $result;
    }

    private function emptyMetrics(): array
    {
        $row = [];
        foreach (self::COUNT_FIELDS as $field) $row[$field] = 0;
        foreach (self::AMOUNT_FIELDS as $field) $row[$field] = '0';
        return $row;
    }

    private function emptyRow(string $date, array $source): array
    {
        return array_merge($this->emptyMetrics(), [
            'source_type' => $source['source_type'], 'source_id' => $source['source_id'],
            'source_code' => $source['source_code'], 'source_name' => $source['source_name'],
            'stat_date' => $date, '__baseline_by_agent' => [],
        ]);
    }

    private function addFact(array &$target, array $fact): void
    {
        foreach (self::COUNT_FIELDS as $field) $target[$field] += (int) ($fact[$field] ?? 0);
        foreach (self::AMOUNT_FIELDS as $field) $target[$field] = bcadd($target[$field], (string) ($fact[$field] ?? '0'), 16);
    }

    private function monthly(array $daily): array
    {
        usort($daily, static fn (array $a, array $b): int => strcmp($a['stat_date'], $b['stat_date']));
        $groups = [];
        foreach ($daily as $day) {
            $month = substr($day['stat_date'], 0, 7);
            $key = implode(':', [$month, $day['source_type'], $day['source_id']]);
            $groups[$key] ??= $this->emptyRow($month, $day);
            $this->addFact($groups[$key], $day);
            foreach ($day['__baseline_by_agent'] as $agentId => $initial) {
                if (!isset($groups[$key]['__baseline_by_agent'][$agentId])) {
                    $groups[$key]['__baseline_by_agent'][$agentId] = $initial;
                }
            }
        }
        foreach ($groups as &$group) $group['initial_user_count'] = array_sum($group['__baseline_by_agent']);
        unset($group);
        return array_values($groups);
    }

    private function responseRow(array $row, string $period, CarbonImmutable $today): array
    {
        $date = $row['stat_date'];
        $row['source_id'] = (string) $row['source_id'];
        $row['id'] = implode(':', [$period, $date, $row['source_type'], $row['source_id']]);
        $row['transfer_in_conversion_rate'] = $this->rate($row['transfer_in_user_count'], $row['initial_user_count']);
        $row['active_rate'] = $this->rate($row['active_user_count'], $row['initial_user_count']);
        $row['stat_status'] = $date === ($period === 'month' ? $today->format('Y-m') : $today->toDateString())
            ? 'processing' : 'completed';
        foreach (self::AMOUNT_FIELDS as $field) $row[$field] = bcadd((string) $row[$field], '0', 16);
        unset($row['__baseline_by_agent']);
        return $row;
    }

    private function rate(int $numerator, int $denominator): string
    {
        return $denominator === 0 ? '0.00' : number_format(round($numerator * 100 / $denominator, 2), 2, '.', '');
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
