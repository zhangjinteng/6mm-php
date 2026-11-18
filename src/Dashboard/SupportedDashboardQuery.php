<?php

declare(strict_types=1);

namespace SixMm\Shared\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use SixMm\Shared\AssetReports\SourceAssetReportSchema;
use SixMm\Shared\FuturesReports\TradeReportAggregator;
use Throwable;

/** Read existing publications only. Scope arguments must come from the authenticated host adapter. */
final class SupportedDashboardQuery
{
    private CarbonImmutable $start;
    private CarbonImmutable $end;

    public function __construct(private ConnectionInterface $db, private string $type, private string $id,
        private $reportFailure = null, private ?ConnectionInterface $predictionDatabase = null)
    {
        if (!in_array($type, ['platform', 'tenant', 'merchant'], true)
            || ($type === 'platform' ? $id !== '0' : !preg_match('/^[1-9][0-9]*$/D', $id))) {
            throw new InvalidArgumentException('无效的看板身份范围');
        }
    }

    public function fetch(string $period = '7', ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $today = $now->startOfDay();
        [$this->start, $this->end] = match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            '7' => [$today->subDays(6), $today],
            '30' => [$today->subDays(29), $today],
            'month' => [$today->startOfMonth(), $today],
            'last-month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()->subDay()],
            default => throw new InvalidArgumentException('无效的统计周期'),
        };
        $result = ['scope' => $this->type, 'period' => $period, 'currency' => 'USDT',
            'range' => ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString()],
            'requested_at' => $now->toIso8601String(), 'blocks' => []];
        foreach (['users', 'trade', 'prediction', 'assets', 'positions', 'partners', 'merchant', 'prediction_runtime'] as $block) {
            if (($block === 'partners' && $this->type !== 'platform')
                || ($block === 'merchant' && $this->type !== 'merchant')
                || ($block === 'prediction_runtime' && $this->predictionDatabase === null)) continue;
            try {
                $result['blocks'][$block] = $this->db->transaction(function () use ($block): array {
                    if ($this->db->getDriverName() === 'pgsql' && $this->db->transactionLevel() === 1) {
                        $this->db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                        $this->db->statement('SET LOCAL statement_timeout = 8000');
                    }
                    return ['state' => 'ready', 'data' => $this->{$block}()];
                });
            } catch (Throwable $error) {
                if (is_callable($this->reportFailure)) ($this->reportFailure)($block, $error);
                $result['blocks'][$block] = ['state' => 'error', 'data' => null];
            }
        }
        return $result;
    }

    private function scoped(string $table): Builder
    {
        return $this->db->table($this->table($table))->where('source_type', $this->type)->where('source_id', $this->id);
    }

    private function period(Builder $query): Builder
    {
        return $query->whereBetween('stat_date', [$this->start->toDateString(), $this->end->toDateString()]);
    }

    private function table(string $table, string $schema = 'public'): string
    {
        return $this->db->getDriverName() === 'pgsql' ? $schema . '.' . $table : $table;
    }

    private function users(): array
    {
        $query = $this->userScope();
        $total = (clone $query)->count();
        $types = (clone $query)->select('user_type')->selectRaw('COUNT(*) AS value')->groupBy('user_type')->get()->all();
        $daily = [];
        $date = $this->db->getDriverName() === 'pgsql' ? "(created_at AT TIME ZONE 'UTC')::date" : 'date(created_at)';
        foreach ((clone $query)->where('created_at', '>=', $this->start->toDateTimeString())
            ->where('created_at', '<', $this->end->addDay()->toDateTimeString())
            ->selectRaw($date . ' AS date, COUNT(*) AS value')->groupByRaw($date)->orderBy('date')->get() as $row) {
            $daily[] = ['date' => (string) $row->date, 'value' => (int) $row->value];
        }
        return ['total' => $total, 'new' => array_sum(array_column($daily, 'value')), 'daily' => $daily,
            'types' => array_map(static fn ($r): array => ['type' => (int) $r->user_type, 'value' => (int) $r->value], $types)];
    }

    private function userScope(): Builder
    {
        $query = $this->db->table($this->table('users'))->whereNull('deleted_at')->where('home_site_id', '>', 0);
        if ($this->type === 'merchant') $query->where('agent_id', $this->id);
        if ($this->type === 'tenant') {
            $query->whereIn('home_site_id', $this->db->table($this->table('wl_sites', 'control_plane'))
                ->where('tenant_id', $this->id)->select('site_id'))->whereRaw('COALESCE(agent_id, 0) = 0');
        }
        return $query;
    }

    private function prediction_runtime(): array
    {
        // A bounded identity set keeps this cross-database read safe for a workbench request.
        $ids = $this->userScope()->limit(10001)->pluck('user_id')->all();
        if (count($ids) > 10000) throw new \RuntimeException('当前订单范围超过即时看板查询上限，请使用预测业务列表');
        $siteIds = $this->type === 'tenant' ? $this->db->table($this->table('wl_sites', 'control_plane'))
            ->where('tenant_id', $this->id)->pluck('site_id')->all() : [];
        $db = $this->predictionDatabase;
        return $db->transaction(function () use ($db, $ids, $siteIds): array {
            if ($db->getDriverName() === 'pgsql' && $db->transactionLevel() === 1) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                $db->statement('SET LOCAL statement_timeout = 3000');
            }
            $native = $db->table('prediction.prediction_order')->whereIn('funding_user_id', $ids)
                ->where('order_source', 'PLAYER')->where('settlement_asset', 'USDT')
                ->whereIn('game_type', ['HIGH_LOW', 'UP_DOWN'])->where('player_query_view', 'HOLDINGS')->where('fund_status', '<>', 'REJECTED');
            $grid = $db->table('grid_contract.grid_orders as o')->whereIn('o.user_id', $ids)
                ->where('o.settlement_asset', 'USDT')->whereNotIn('o.status', [5, 6, 7])
                ->whereExists(function (Builder $q): void {
                    $q->selectRaw('1')->from('grid_contract.grid_order_state_logs as s')
                        ->whereColumn('s.order_id', 'o.order_id')->where('s.to_status', 2);
                });
            $pending = (clone $native)->where('business_status', 'ACTIVE')->where('fund_status', 'DEBITED')->where('result', 'PENDING')
                ->select('game_type')->selectRaw('COUNT(*) AS count, SUM(stake_amount) AS amount')->groupBy('game_type')->get()
                ->map(fn ($r): array => ['play' => strtolower($r->game_type), 'count' => (int) $r->count, 'amount' => (string) $r->amount])->all();
            $gridPending = (clone $grid)->selectRaw('COUNT(*) AS count, SUM(o.stake_amount_units) AS amount')->first();
            $pending[] = ['play' => 'grid', 'count' => (int) $gridPending->count,
                'amount' => bcdiv((string) ($gridPending->amount ?? '0'), '100000000', 16)];
            $orders = (clone $native)->orderByDesc('created_at')->limit(8)
                ->get(['order_id', 'game_type', 'symbol', 'stake_amount', 'business_status', 'fund_status', 'created_at'])
                ->map(fn ($r): array => ['id' => (string) $r->order_id, 'play' => strtolower($r->game_type), 'symbol' => $r->symbol,
                    'amount' => (string) $r->stake_amount, 'state' => $r->business_status . ' / ' . $r->fund_status, 'created_at' => $r->created_at])->all();
            foreach ((clone $grid)->orderByDesc('o.created_at')->limit(8)->get(['o.order_id', 'o.symbol', 'o.stake_amount_units', 'o.status', 'o.created_at']) as $r) {
                $orders[] = ['id' => (string) $r->order_id, 'play' => 'grid', 'symbol' => $r->symbol,
                    'amount' => bcdiv((string) $r->stake_amount_units, '100000000', 16), 'state' => 'GRID_' . $r->status, 'created_at' => $r->created_at];
            }
            usort($orders, fn ($a, $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
            $rounds = $db->table('prediction.prediction_round')->whereIn('state', ['PREPARING', 'BETTING', 'LOCKED', 'OBSERVING', 'RESULTING', 'SETTLING']);
            if ($this->type === 'tenant') $rounds->whereIn('routing_site_id', $siteIds)->where('agent_id', 0);
            if ($this->type === 'merchant') $rounds->where('agent_id', $this->id);
            return ['orders' => array_slice($orders, 0, 8), 'pending' => $pending,
                'rounds' => $rounds->orderByDesc('created_at')->limit(8)->get(['round_id', 'symbol', 'state', 'bet_close_at', 'created_at'])
                    ->map(fn ($r): array => ['id' => (string) $r->round_id, 'symbol' => $r->symbol, 'state' => $r->state, 'closes_at' => $r->bet_close_at])->all(),
                'as_of' => CarbonImmutable::now('UTC')->toIso8601String()];
        });
    }

    private function trade(): array
    {
        $query = $this->period($this->scoped('admin_futures_trade_report_daily'));
        $rows = (clone $query)->where('symbol', '')->orderBy('stat_date')->get()->map(fn ($r) => (array) $r)->all();
        $totals = $rows === [] ? null : (new TradeReportAggregator())->mergeDaily($rows, $this->start->toDateString());
        if ($totals !== null) unset($totals['identities']);
        $symbols = (clone $query)->where('symbol', '<>', '')->select('symbol')->selectRaw('SUM(trade_amount) AS value')
            ->groupBy('symbol')->orderByDesc('value')->limit(8)->get()->map(fn ($r): array => [
                'name' => (string) $r->symbol, 'value' => (string) $r->value])->all();
        $feeRows = $this->period($this->scoped('admin_futures_fee_report_daily'))->where('symbol', '')->orderBy('stat_date')->get();
        $fees = $feeRows->isEmpty() ? null : $this->sum($feeRows->all(), 'total_handling_fee');
        return ['totals' => $totals, 'fees' => $fees, 'symbols' => $symbols,
            'daily' => array_map(fn ($r): array => ['date' => $r['stat_date'], 'value' => (string) $r['trade_amount'],
                'orders' => (int) $r['order_count'], 'fills' => (int) $r['fill_count']], $rows),
            'coverage' => $this->coverage($rows), 'fee_coverage' => $this->coverage($feeRows->all())];
    }

    private function prediction(): array
    {
        $query = $this->period($this->scoped('admin_prediction_user_pnl_daily'))->where('settle_asset', 'USDT');
        $rows = (clone $query)->where('play_type', 'all')->where('symbol', '*')->orderBy('stat_date')->get()->all();
        $totals = null;
        if ($rows !== []) {
            $totals = [];
            foreach (['stake_amount', 'valid_stake_amount', 'return_amount', 'platform_pnl'] as $field) $totals[$field] = $this->sum($rows, $field);
            foreach (['order_count', 'win_order_count', 'lose_order_count', 'refund_order_count'] as $field) $totals[$field] = array_sum(array_map(fn ($r) => (int) $r->{$field}, $rows));
        }
        $group = function (string $dimension) use ($query): array {
            $q = (clone $query)->where($dimension === 'symbol' ? 'play_type' : 'symbol', $dimension === 'symbol' ? 'all' : '*')
                ->where($dimension, '<>', $dimension === 'symbol' ? '*' : 'all');
            return $q->select($dimension)->selectRaw('SUM(stake_amount) AS value')->groupBy($dimension)->orderByDesc('value')->limit(8)
                ->get()->map(fn ($r): array => ['name' => (string) $r->{$dimension}, 'value' => (string) $r->value])->all();
        };
        return ['totals' => $totals, 'daily' => array_map(fn ($r): array => ['date' => $r->stat_date,
                'stake' => (string) $r->stake_amount, 'returned' => (string) $r->return_amount, 'pnl' => (string) $r->platform_pnl], $rows),
            'plays' => $group('play_type'), 'symbols' => $group('symbol'), 'coverage' => $this->coverage($rows)];
    }

    private function assets(): array
    {
        $rows = $this->period($this->scoped(SourceAssetReportSchema::TABLE))->where('currency', 'USDT')
            ->whereIn('account_type', ['funding', 'contract'])->orderBy('stat_date')->get()->all();
        $days = []; $latest = ['funding' => null, 'contract' => null]; $asOf = [];
        foreach ($rows as $row) {
            $closing = $this->json($row->asset_report_closing); $flows = $this->json($row->asset_report_flows);
            $valid = ($closing['version'] ?? null) === SourceAssetReportSchema::VERSION && ($closing['user_types'] ?? '') === 'all';
            $validFlows = ($flows['version'] ?? null) === SourceAssetReportSchema::VERSION && ($flows['user_types'] ?? '') === 'all';
            $day = &$days[$row->stat_date];
            $day ??= ['date' => $row->stat_date, 'deposit' => null, 'withdraw' => null, 'funding' => null, 'contract' => null];
            $day[$row->account_type] = $valid ? ($closing['total'] ?? null) : null;
            // Funding ledger external confirmed movements only; never add internal contract transfers again.
            if ($row->account_type === 'funding' && $validFlows) {
                $day['deposit'] = $flows['deposit_in'] ?? null; $day['withdraw'] = $flows['withdraw_out'] ?? null;
            }
            if ($row->stat_date === $this->end->toDateString() && $valid) {
                $latest[$row->account_type] = array_intersect_key($closing, array_flip(['total', 'available', 'occupied']));
                $asOf[] = $closing['captured_at'] ?? null;
            }
            unset($day);
        }
        $fundingDays = array_filter($rows, function ($r): bool {
            $flows = $this->json($r->asset_report_flows);
            return $r->account_type === 'funding' && ($flows['version'] ?? null) === SourceAssetReportSchema::VERSION
                && ($flows['user_types'] ?? '') === 'all';
        });
        $asOf = array_filter($asOf);
        return ['balances' => $latest, 'as_of' => $asOf === [] ? null : min($asOf),
            'daily' => array_values($days), 'coverage' => $this->coverage(array_values($fundingDays))];
    }

    private function positions(): array
    {
        // Latest publication is explicitly labelled a snapshot, not a live price query.
        $row = $this->scoped('admin_futures_position_report_daily')->where('symbol', '')->orderByDesc('stat_date')->first();
        return ['snapshot' => $row === null ? null : array_intersect_key((array) $row, array_flip([
            'long_position_amount', 'short_position_amount', 'closing_position_amount', 'occupied_margin',
            'unrealized_pnl', 'snapshot_at', 'status', 'stat_date',
        ]))];
    }

    private function partners(): array
    {
        $merchants = $this->db->table($this->table('agents'))->where('pid', 0)->whereNull('deleted_at')->count();
        $tenants = $this->db->table($this->table('wl_tenants', 'control_plane'))->count();
        $ranks = $this->period($this->db->table($this->table('admin_futures_trade_report_daily')))->where('symbol', '')
            ->whereIn('source_type', ['merchant', 'tenant'])->select('source_type', 'source_id', 'source_name')
            ->selectRaw('SUM(trade_amount) AS value')->groupBy('source_type', 'source_id', 'source_name')->orderByDesc('value')->limit(8)->get();
        $callbacks = $this->db->table($this->table('agent_webhook_task'))->select('status')->selectRaw('COUNT(*) AS value')->groupBy('status')->get();
        return ['merchants' => $merchants, 'tenants' => $tenants,
            'ranks' => $ranks->map(fn ($r): array => ['name' => $r->source_name, 'type' => $r->source_type, 'value' => (string) $r->value])->all(),
            'callbacks' => $callbacks->map(fn ($r): array => ['status' => (string) $r->status, 'value' => (int) $r->value])->all()];
    }

    private function merchant(): array
    {
        $balance = $this->db->table($this->table('agent_assets'))->where('agent_id', $this->id)
            ->where('currency', 'USDT')->whereNull('deleted_at')->value('deposit_balance');
        $used = (string) $this->db->table($this->table('user_assets') . ' as a')
            ->join($this->table('users') . ' as u', 'u.user_id', '=', 'a.user_id')->where('u.agent_id', $this->id)
            ->where('a.asset_type', 'USDT')->whereNull('a.deleted_at')->whereNull('u.deleted_at')->sum('a.wallet_balance');
        $logs = $this->db->table($this->table('agent_account_change_log'))->where('agent_id', $this->id)
            ->where('currency', 'USDT')->whereNull('deleted_at')->where('created_at', '>=', $this->start->toDateTimeString())
            ->where('created_at', '<', $this->end->addDay()->toDateTimeString());
        $commission = (string) (clone $logs)->where('biz_type', 'commission_rebate')->sum('delta_amount');
        $net = (string) (clone $logs)->sum('delta_amount');
        $available = $balance === null ? null : bcsub((string) $balance, $used, 16);
        $anomalies = $this->db->table($this->table('agent_transfer_orders'))->where('agent_id', $this->id)
            ->whereNotIn('status', ['success', 'SUCCESS'])->select('status')->selectRaw('COUNT(*) AS value')->groupBy('status')->get();
        return ['balance' => $balance === null ? null : (string) $balance, 'used' => $used, 'available' => $available,
            'available_rate' => $balance === null || bccomp((string) $balance, '0', 16) <= 0 ? null
                : bcmul(bcdiv((string) $available, (string) $balance, 16), '100', 8),
            'commission' => $commission, 'net' => $net,
            'transfers' => $anomalies->map(fn ($r): array => ['status' => (string) $r->status, 'value' => (int) $r->value])->all()];
    }

    private function coverage(array $rows): array
    {
        $dates = []; $updated = null; $incomplete = false;
        foreach ($rows as $row) {
            $r = (array) $row; $dates[(string) $r['stat_date']] = true;
            $stamp = $r['updated_at'] ?? null;
            if ($stamp !== null && ($updated === null || strcmp((string) $stamp, $updated) > 0)) $updated = (string) $stamp;
            if (isset($r['status']) && (int) $r['status'] !== 1) $incomplete = true;
        }
        $missing = [];
        for ($date = $this->start; $date->lte($this->end); $date = $date->addDay()) {
            if (!isset($dates[$date->toDateString()])) $missing[] = $date->toDateString();
        }
        return ['missing_dates' => $missing, 'updated_at' => $updated, 'incomplete' => $incomplete];
    }

    private function sum(iterable $rows, string $field): string
    {
        $sum = '0';
        foreach ($rows as $row) $sum = bcadd($sum, (string) ((array) $row)[$field], 16);
        return $sum;
    }

    private function json(mixed $value): array
    {
        if ($value === null) return [];
        $result = is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($result) ? $result : [];
    }
}
