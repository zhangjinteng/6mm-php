<?php

declare(strict_types=1);

namespace SixMm\Shared\ManualAdjustments;

use SixMm\Shared\UserActions\UserAccountChangeOrder;
use SixMm\Shared\TradeFills\TradeFillOwnerScope;
use SixMm\Shared\DataScope\UserIdentityKeywordScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\DataScope\AllUsersScope;
use SixMm\Shared\DataScope\BusinessOwnerScope;
use SixMm\Shared\TradeFills\TradeFillOwnerDirectory;

final class ManualAdjustmentQueryService
{
    public function __construct(private TradeFillOwnerScope $owners) {}

    public function search(array $params): array
    {
        $sort = $params['order_by'] ?? 'created_at';
        $direction = $params['order_dir'] ?? 'desc';
        $size = (int) ($params['page_size'] ?? 20);
        $fingerprint = $this->fingerprint($params);
        $cursor = $this->decodeCursor($params['cursor'] ?? null, $sort, $direction, $fingerprint);
        $forward = $cursor['forward'] ?? true;
        $ascending = ($direction === 'asc') === $forward;
        $rows = [];
        // Each source supplies at most one page plus a look-ahead row, not its entire history.
        foreach (['contract', 'funding'] as $type) {
            if (!empty($params['account_type']) && $params['account_type'] !== $type) continue;
            $batch = $type === 'contract'
                ? $this->contractRows($params, $sort, $ascending, $cursor, $size + 1)
                : $this->fundingRows($params, $sort, $ascending, $cursor, $size + 1);
            array_push($rows, ...$batch);
        }
        usort($rows, fn (array $a, array $b): int => $this->compare($a, $b) * ($ascending ? 1 : -1));
        $hasExtra = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        if (!$forward) $rows = array_reverse($rows);
        $hasPrevious = $rows !== [] && ($forward ? $cursor !== null : $hasExtra);
        $hasMore = $rows !== [] && ($forward ? $hasExtra : $cursor !== null);
        $previous = $hasPrevious ? $this->encodeCursor($rows[0], false, $sort, $direction, $fingerprint) : null;
        $next = $hasMore ? $this->encodeCursor($rows[count($rows) - 1], true, $sort, $direction, $fingerprint) : null;
        foreach ($rows as &$row) unset($row['_sort']);
        unset($row);
        (new TradeFillOwnerDirectory(DB::connection()))->appendToRows($rows);
        return [
            'lists' => $rows, 'page_size' => $size, 'has_more' => $hasMore, 'has_previous' => $hasPrevious,
            'next_cursor' => $next, 'previous_cursor' => $previous,
        ];
    }

    private function users(array $params): Builder
    {
        $query = DB::table('users')->whereNull('users.deleted_at');
        (new UserIdentityKeywordScope(
            new AllUsersScope(),
            (string) ($params['keyword'] ?? '')
        ))->apply($query, 'users.agent_id');
        if (isset($params['username']) && $params['username'] !== '') $query->where('users.username', 'like', '%' . $params['username'] . '%');
        return $query;
    }

    private function contractRows(array $params, string $sort, bool $ascending, ?array $cursor, int $limit): array
    {
        $prefix = DB::getDriverName() === 'pgsql' ? 'control_plane.' : '';
        $query = DB::table('user_account_change_order as orders')
            ->leftJoin('users', 'users.user_id', '=', 'orders.user_id')
            ->leftJoin('admin as operators', static function (JoinClause $join): void {
                $join->on('operators.id', '=', 'orders.operator_id')->where('orders.home_site_id', '=', 0)
                    ->where('orders.agent_id', '=', 0)->whereNull('operators.deleted_at');
            })
            ->leftJoin($prefix . 'wl_sites as origin_site', static function (JoinClause $join): void {
                $join->on('origin_site.site_id', '=', 'orders.home_site_id')
                    ->where('orders.home_site_id', '>', 0)->where('orders.agent_id', '=', 0);
            })
            ->leftJoin($prefix . 'wl_tenant_account as wl_operator', static function (JoinClause $join): void {
                $join->on('wl_operator.id', '=', 'orders.operator_id')->on('wl_operator.tenant_id', '=', 'origin_site.tenant_id');
            })
            ->leftJoin('user_account_change_log as logs', static function (JoinClause $join): void {
                $join->on('logs.ledger_id', '=', 'orders.ledger_id')
                    ->on('logs.user_id', '=', 'orders.user_id')->on('logs.amount', '=', 'orders.amount')
                    ->where('logs.ledger_id', '<>', '')->where('logs.ledger_entry_type', 'wallet_change')
                    ->whereRaw('UPPER(logs.asset_type) = UPPER(orders.asset_type)')
                    ->whereRaw('LOWER(logs.change_type) = ?', ['admin_adjust'])->whereNull('logs.deleted_at');
            })
            ->whereNull('orders.deleted_at')->where('orders.status', UserAccountChangeOrder::STATUS_SUCCESS)
            ->whereIn('orders.order_type', [UserAccountChangeOrder::TYPE_ADMIN_RECHARGE, UserAccountChangeOrder::TYPE_ADMIN_DEDUCT])
            ->select([
                'orders.id', 'orders.order_no', 'orders.ledger_id', 'logs.id as flow_id', 'orders.user_id as platform_user_id',
                DB::raw('CASE WHEN logs.id IS NULL THEN orders.home_site_id ELSE logs.home_site_id END AS home_site_id'),
                DB::raw('CASE WHEN logs.id IS NULL THEN orders.agent_id ELSE logs.agent_id END AS agent_id'),
                'orders.home_site_id as origin_site_id', 'orders.agent_id as origin_agent_id',
                'users.public_user_id as user_id', 'users.username', 'users.nick_name as nice_name',
                'orders.asset_type', 'orders.symbol', 'orders.amount', 'orders.operator_id',
                DB::raw('COALESCE(operators.account, wl_operator.account) AS operator_account'),
                DB::raw('COALESCE(operators.name, wl_operator.name) AS operator_display_name'),
                'orders.description', 'orders.detail', 'orders.created_at',
                'logs.wallet_balance_before', 'logs.wallet_balance_after', 'logs.frozen_balance_before', 'logs.frozen_balance_after',
            ]);
        $owners = $this->owners;
        $query->where(static function (Builder $visible) use ($owners): void {
            $visible->where(static function (Builder $linked) use ($owners): void {
                (new BusinessOwnerScope($owners, 'logs.home_site_id'))->apply($linked, 'logs.agent_id');
            })->orWhere(static function (Builder $unlinked) use ($owners): void {
                // White-label adjustments can only target direct users of the initiating tenant.
                // Its recorded site therefore identifies the tenant even before the wallet entry arrives.
                // This is NOT true for platform adjustments; never infer their target from their origin.
                $unlinked->whereNull('logs.id')->where('orders.home_site_id', '>', 0)->where('orders.agent_id', 0);
                (new BusinessOwnerScope($owners, 'orders.home_site_id'))->apply($unlinked, 'orders.agent_id');
            });
        });
        (new UserIdentityKeywordScope(new AllUsersScope(), (string) ($params['keyword'] ?? '')))->apply($query, 'users.agent_id');
        if (trim((string) ($params['keyword'] ?? '')) !== '' || (isset($params['username']) && $params['username'] !== '')) $query->whereNull('users.deleted_at');
        if (isset($params['username']) && $params['username'] !== '') $query->where('users.username', 'like', '%' . $params['username'] . '%');
        if (!empty($params['flow_id'])) $query->where('logs.id', $params['flow_id']);
        if (!empty($params['order_no'])) $query->where('orders.order_no', $params['order_no']);
        if (!empty($params['direction'])) $query->where('orders.amount', $params['direction'] === 'inc' ? '>' : '<', 0);
        $this->filters($query, $params, 'orders.created_at', 'orders.asset_type');
        $expression = $sort === 'created_at' ? $this->timeExpression($query->getConnection(), 'orders.created_at') : 'orders.' . $sort;
        $this->seek($query, $expression, 'orders.id', 'contract', $ascending, $cursor);
        return $query->selectRaw($expression . ' as _sort')->limit($limit)->get()->map(function (object $item) use ($sort): array {
            $row = (array) $item;
            if ($sort === 'amount') $row['_sort'] = (string) $row['amount'];
            $detail = json_decode((string) $row['detail'], true);
            $row['_ledger_sync'] = is_array($detail) ? ($detail['ledger_sync'] ?? []) : [];
            $reason = is_array($detail) && is_string($detail['reason'] ?? null) ? trim($detail['reason']) : trim((string) $row['description']);
            $legacy = !is_array($detail) || !array_key_exists('reason', $detail);
            $row['reason'] = $reason === '' || ($legacy && in_array($reason, ['后台充值', '后台扣减', '后台扣除'], true)) ? null : $reason;
            unset($row['description'], $row['detail']);
            return $this->normalize($row, 'contract');
        })->all();
    }

    private function fundingRows(array $params, string $sort, bool $ascending, ?array $cursor, int $limit): array
    {
        $db = DB::connection(config('asset.connection'));
        $amount = "(CASE WHEN adjustments.direction = 'DEBIT' THEN -CAST(adjustments.amount AS NUMERIC) ELSE CAST(adjustments.amount AS NUMERIC) END)"
            . ($db->getDriverName() === 'pgsql' ? ' / POWER(CAST(10 AS NUMERIC), assets.internal_scale)' : ' / POWER(10.0, assets.internal_scale)');
        $query = $db->table('funding_adjustments as adjustments')
            ->join('funding_accounts as accounts', static function (JoinClause $join): void {
                $join->on('accounts.account_id', '=', 'adjustments.account_id')
                    ->on('accounts.home_site_id', '=', 'adjustments.home_site_id')->on('accounts.user_id', '=', 'adjustments.user_id');
            })
            ->join('funding_assets as assets', 'assets.asset_id', '=', 'adjustments.asset_id')
            ->leftJoin('funding_ledger_entries as ledger', static function (JoinClause $join): void {
                $join->on('ledger.ledger_id', '=', 'adjustments.ledger_id')
                    ->on('ledger.user_id', '=', 'adjustments.user_id')->on('ledger.account_id', '=', 'adjustments.account_id')
                    ->on('ledger.home_site_id', '=', 'adjustments.home_site_id')->on('ledger.asset_id', '=', 'adjustments.asset_id')
                    ->on('ledger.business_id', '=', 'adjustments.business_id')->where('ledger.business_type', 'ADJUSTMENT')
                    ->whereRaw("ledger.entry_type = CASE WHEN adjustments.direction = 'CREDIT' THEN 'ADJUSTMENT_CREDIT' ELSE 'ADJUSTMENT_DEBIT' END")
                    ->whereRaw("(ledger.available_delta + ledger.held_delta) = CASE WHEN adjustments.direction = 'CREDIT' THEN CAST(adjustments.amount AS NUMERIC) ELSE -CAST(adjustments.amount AS NUMERIC) END");
            })
            ->where('adjustments.status', 'EXECUTED')->whereIn('adjustments.direction', ['CREDIT', 'DEBIT'])
            ->where('adjustments.amount', '>', 0)
            ->select([
                'adjustments.adjustment_id as id', 'adjustments.business_id as order_no', 'adjustments.ledger_id',
                'ledger.ledger_id as flow_id', 'adjustments.user_id as platform_user_id',
                'adjustments.home_site_id', 'accounts.agent_id',
                'adjustments.amount as amount_atomic', 'adjustments.direction as funding_direction',
                'adjustments.requester_identity', 'adjustments.requester_operator_id as operator_id',
                'adjustments.reason_detail as reason', 'adjustments.created_at',
                'assets.asset_code as asset_type', 'assets.internal_scale',
                'ledger.available_before', 'ledger.available_after', 'ledger.held_before', 'ledger.held_after',
            ]);
        (new BusinessOwnerScope($this->owners, 'adjustments.home_site_id', true))->apply($query, 'accounts.agent_id');
        if ((isset($params['keyword']) && $params['keyword'] !== '')
            || (isset($params['username']) && $params['username'] !== '')) {
            $query->whereIn('adjustments.user_id', $this->users($params)->pluck('users.user_id')->all());
        }
        if (!empty($params['flow_id'])) $query->where('ledger.ledger_id', $params['flow_id']);
        if (!empty($params['order_no'])) $query->whereRaw('CAST(adjustments.business_id AS TEXT) = ?', [$params['order_no']]);
        if (!empty($params['direction'])) $query->where('adjustments.direction', $params['direction'] === 'inc' ? 'CREDIT' : 'DEBIT');
        $this->filters($query, $params, 'adjustments.created_at', 'assets.asset_code');
        $expression = $sort === 'amount' ? $amount : ($sort === 'id' ? 'adjustments.adjustment_id' : $this->timeExpression($db, 'adjustments.created_at'));
        $rows = [];
        do {
            $page = clone $query;
            $this->seek($page, $expression, 'adjustments.adjustment_id', 'funding', $ascending, $cursor);
            $items = $page->selectRaw($expression . ' as _sort')->limit($limit)->get();
            if ($items->isEmpty()) break;
            $users = $this->users($params)->whereIn('users.user_id', $items->pluck('platform_user_id')->all())
                ->get(['user_id', 'public_user_id', 'username', 'nick_name'])->keyBy('user_id');
            $operatorIds = $items->where('requester_identity', 'admin')->pluck('operator_id')->all();
            $operators = DB::table('admin')->whereIn('id', $operatorIds)->whereNull('deleted_at')->get(['id', 'account', 'name'])->keyBy('id');
            $whiteLabelRows = $items->where('requester_identity', 'agent_admin');
            $whiteLabelOperators = collect();
            if ($whiteLabelRows->isNotEmpty()) {
                $schema = DB::getDriverName() === 'pgsql' ? 'control_plane.' : '';
                $whiteLabelOperators = DB::table($schema . 'wl_tenant_account as actor')
                    ->join($schema . 'wl_sites as site', 'site.tenant_id', '=', 'actor.tenant_id')
                    ->whereIn('actor.id', $whiteLabelRows->pluck('operator_id')->unique())
                    ->whereIn('site.site_id', $whiteLabelRows->pluck('home_site_id')->unique())
                    ->get(['site.site_id', 'actor.id', 'actor.account', 'actor.name'])
                    ->keyBy(static fn (object $actor): string => $actor->site_id . ':' . $actor->id);
            }
            foreach ($items as $item) {
                $cursor = ['value' => (string) $item->_sort, 'id' => (string) $item->id, 'account_type' => 'funding'];
                $user = $users->get($item->platform_user_id);
                $row = (array) $item;
                $scale = (int) $row['internal_scale'];
                $signed = BigDecimal::of($row['amount_atomic'])->withPointMovedLeft($scale);
                $row['amount'] = (string) ($row['funding_direction'] === 'DEBIT' ? $signed->negated() : $signed);
                if ($sort === 'amount') $row['_sort'] = $row['amount'];
                foreach (['before', 'after'] as $boundary) {
                    $available = $row['available_' . $boundary];
                    $held = $row['held_' . $boundary];
                    $row['wallet_balance_' . $boundary] = $available === null || $held === null ? null
                        : (string) BigDecimal::of($available)->plus($held)->withPointMovedLeft($scale);
                    $row['frozen_balance_' . $boundary] = $held === null ? null : (string) BigDecimal::of($held)->withPointMovedLeft($scale);
                }
                $row['user_id'] = $user->public_user_id ?? null;
                $row['username'] = $user->username ?? null;
                $row['nice_name'] = $user->nick_name ?? null;
                $row['symbol'] = '';
                $operator = match ($row['requester_identity']) {
                    'admin' => $operators->get($row['operator_id']),
                    'agent_admin' => $whiteLabelOperators->get($row['home_site_id'] . ':' . $row['operator_id']),
                    default => null,
                };
                $row['operator_account'] = $operator->account ?? null;
                $row['operator_display_name'] = $operator->name ?? null;
                $row['operator_name'] = $row['requester_identity'] === 'admin' ? null : $row['requester_identity'] . ' / ID ' . $row['operator_id'];
                foreach (['amount_atomic', 'funding_direction', 'internal_scale', 'available_before', 'available_after', 'held_before', 'held_after'] as $key) unset($row[$key]);
                $rows[] = $this->normalize($row, 'funding');
                if (count($rows) === $limit) break;
            }
            // Identity hydration never discards an owner-authorized adjustment.
        } while (count($rows) < $limit && count($items) === $limit);
        return $rows;
    }

    private function filters(Builder $query, array $params, string $date, string $asset): void
    {
        if (!empty($params['asset_type'])) $query->whereRaw('UPPER(' . $asset . ') = ?', [strtoupper($params['asset_type'])]);
        foreach (['start_time' => '>=', 'end_time' => '<'] as $key => $operator) {
            if (empty($params[$key])) continue;
            $boundary = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $params[$key], (string) config('app.timezone', 'UTC'))->utc();
            if ($key === 'end_time') $boundary = $boundary->addSecond();
            $query->where($date, $operator, $boundary->format($query->getConnection()->getDriverName() === 'pgsql' ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d H:i:s'));
        }
    }

    private function timeExpression(ConnectionInterface $db, string $column): string
    {
        return $db->getDriverName() === 'pgsql' ? 'COALESCE(EXTRACT(EPOCH FROM ' . $column . ') * 1000000, 0)'
            : "COALESCE(CAST(strftime('%s', substr($column, 1, 19)) AS INTEGER) * 1000000 + CAST(substr(substr($column, 21) || '000000', 1, 6) AS INTEGER), 0)";
    }

    private function seek(Builder $query, string $expression, string $id, string $type, bool $ascending, ?array $cursor): void
    {
        $operator = $ascending ? '>' : '<';
        if ($cursor !== null) {
            $query->where(static function (Builder $nested) use ($expression, $id, $type, $ascending, $operator, $cursor): void {
                $nested->whereRaw($expression . ' ' . $operator . ' CAST(? AS NUMERIC)', [$cursor['value']]);
                $typeComparison = strcmp($type, $cursor['account_type']);
                if (($ascending && $typeComparison > 0) || (!$ascending && $typeComparison < 0)) {
                    $nested->orWhereRaw($expression . ' = CAST(? AS NUMERIC)', [$cursor['value']]);
                } elseif ($typeComparison === 0) {
                    $nested->orWhere(static function (Builder $tie) use ($expression, $id, $operator, $cursor): void {
                        $tie->whereRaw($expression . ' = CAST(? AS NUMERIC)', [$cursor['value']])->where($id, $operator, $cursor['id']);
                    });
                }
            });
        }
        $query->orderByRaw($expression . ($ascending ? ' ASC' : ' DESC'))->orderBy($id, $ascending ? 'asc' : 'desc');
    }

    private function normalize(array $row, string $type): array
    {
        foreach (['id', 'platform_user_id', 'user_id', 'amount', 'wallet_balance_before', 'wallet_balance_after', 'frozen_balance_before', 'frozen_balance_after', 'operator_id', 'flow_id', 'ledger_id'] as $field) {
            $row[$field] = $row[$field] === null || $row[$field] === '' ? null : (string) $row[$field];
        }
        $sync = $row['_ledger_sync'] ?? [];
        $complete = $row['flow_id'] !== null && $row['wallet_balance_before'] !== null
            && $row['wallet_balance_after'] !== null && $row['frozen_balance_after'] !== null;
        $started = $sync['queued_at'] ?? $row['created_at'];
        $stale = $started === null || CarbonImmutable::parse($started, 'UTC')->addMinutes(15)->isPast();
        $row['ledger_sync_status'] = $complete ? 'linked'
            : ((($sync['state'] ?? '') === 'failed' || $stale) ? 'failed' : 'pending');
        unset($row['_ledger_sync']);
        $row['record_key'] = $type . ':' . $row['id'];
        $row['order_no'] = (string) $row['order_no'];
        $row['operator_account'] = trim((string) $row['operator_account']) ?: null;
        $row['operator_display_name'] = trim((string) $row['operator_display_name']) ?: null;
        $labels = array_unique(array_filter([$row['operator_account'], $row['operator_display_name']], static fn ($value): bool => $value !== null));
        $row['operator_name'] = $labels !== [] ? implode(' / ', $labels) : ($row['operator_name'] ?? ((int) $row['operator_id'] > 0 ? 'ID ' . $row['operator_id'] : null));
        $amount = BigDecimal::of($row['amount']);
        $row['amount'] = (string) $amount;
        $row['direction'] = $amount->isPositive() ? 'inc' : ($amount->isNegative() ? 'dec' : '');
        $row['account_type'] = $type;
        $row['change_type'] = 'admin_adjust';
        $row['change_type_parse'] = $type === 'contract' && (int) $row['origin_site_id'] > 0
            && (string) $row['origin_agent_id'] === '0' ? '白标合约调账' : '后台划转';
        unset($row['origin_site_id'], $row['origin_agent_id']);
        $row['available_balance_after'] = $row['wallet_balance_after'] === null || $row['frozen_balance_after'] === null ? null
            : (string) BigDecimal::of($row['wallet_balance_after'])->minus($row['frozen_balance_after']);
        $row['created_at'] = $row['created_at'] === null ? null : CarbonImmutable::parse($row['created_at'], 'UTC')
            ->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
        $row['_sort'] = (string) BigDecimal::of((string) $row['_sort']);
        return $row;
    }

    private function compare(array $a, array $b): int
    {
        return BigDecimal::of($a['_sort'])->compareTo($b['_sort']) ?: strcmp($a['account_type'], $b['account_type'])
            ?: BigDecimal::of($a['id'])->compareTo($b['id']);
    }

    private function fingerprint(array $params): string
    {
        $filters = ['owners' => $this->owners->cacheKey()];
        foreach (['flow_id', 'order_no', 'keyword', 'username', 'asset_type', 'direction', 'source_type', 'agent_id', 'tenant_id', 'start_time', 'end_time', 'account_type'] as $key) {
            $filters[$key] = (string) ($params[$key] ?? '');
        }
        return hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
    }

    private function encodeCursor(array $row, bool $forward, string $sort, string $direction, string $fingerprint): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'version' => 1, 'sort' => $sort, 'direction' => $direction, 'filters' => $fingerprint,
            'value' => $row['_sort'], 'account_type' => $row['account_type'], 'id' => $row['id'], 'forward' => $forward,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $value, string $sort, string $direction, string $fingerprint): ?array
    {
        if ($value === null || $value === '') return null;
        $data = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['sort'] ?? null) !== $sort
            || ($data['direction'] ?? null) !== $direction || ($data['filters'] ?? null) !== $fingerprint
            || !in_array($data['account_type'] ?? null, ['contract', 'funding'], true)
            || !is_bool($data['forward'] ?? null) || !is_string($data['id'] ?? null)
            || !preg_match('/^[1-9][0-9]{0,18}$/D', $data['id']) || BigDecimal::of($data['id'])->compareTo(PHP_INT_MAX) > 0
            || !is_string($data['value'] ?? null)
            || !preg_match('/^-?[0-9]{1,80}(?:\.[0-9]{1,30})?$/D', $data['value'])) {
            throw ValidationException::withMessages(['cursor' => '分页条件已变更或游标无效，请重新查询']);
        }
        return $data;
    }
}
