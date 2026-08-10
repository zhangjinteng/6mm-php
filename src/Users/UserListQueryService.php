<?php

declare(strict_types=1);

namespace SixMm\Shared\Users;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class UserListQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];
    private const USER_TYPE_LIVE = 1;
    private const USER_TYPE_INTERNAL = 2;

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(UserListQuery $criteria, UserDataScope $scope): PageResult
    {
        $bindings = $this->connection
            ->table('agent_user_bindings')
            ->select([
                'agent_id',
                'platform_user_id',
                $this->connection->raw('MAX(agent_user_id) AS agent_user_id'),
            ])
            ->whereIn('bind_status', self::ACTIVE_BINDING_STATUSES)
            ->groupBy('agent_id', 'platform_user_id');

        $walletBalances = $this->connection
            ->table('user_assets')
            ->select([
                'user_id',
                $this->connection->raw('COALESCE(SUM(wallet_balance), 0) AS wallet_balance'),
            ])
            ->whereNull('deleted_at')
            ->groupBy('user_id');

        $volumes = $this->connection
            ->table('user_daily_volume')
            ->select([
                'user_id',
                $this->connection->raw('COALESCE(SUM(volume), 0) AS volume_30d'),
            ])
            ->where('trade_date', '>=', $criteria->volumeSince())
            ->groupBy('user_id');

        $latestBalanceDates = $this->connection
            ->table('user_daily_balance')
            ->select([
                'user_id',
                $this->connection->raw('MAX(trade_date) AS trade_date'),
            ])
            ->groupBy('user_id');
        $latestPnl = $this->connection
            ->table('user_daily_balance AS udb')
            ->joinSub($latestBalanceDates, 'latest_balance_dates', static function (JoinClause $join): void {
                $join->on('latest_balance_dates.user_id', '=', 'udb.user_id')
                    ->on('latest_balance_dates.trade_date', '=', 'udb.trade_date');
            })
            ->select([
                'udb.user_id',
                $this->connection->raw('MAX(COALESCE(udb.realized_pnl_cumulative, 0)) AS total_realized_pnl'),
            ])
            ->groupBy('udb.user_id');

        $query = $this->connection
            ->table('users')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join): void {
                $join->on('aub.agent_id', '=', 'users.agent_id')
                    ->on('aub.platform_user_id', '=', 'users.user_id');
            })
            ->leftJoinSub($walletBalances, 'asset_summary', static function (JoinClause $join): void {
                $join->on('asset_summary.user_id', '=', 'users.user_id');
            })
            ->leftJoinSub($volumes, 'volume_summary', static function (JoinClause $join): void {
                $join->on('volume_summary.user_id', '=', 'users.user_id');
            })
            ->leftJoinSub($latestPnl, 'latest_pnl', static function (JoinClause $join): void {
                $join->on('latest_pnl.user_id', '=', 'users.user_id');
            })
            ->whereNull('users.deleted_at');

        $scope->apply($query, 'users.agent_id');
        $this->applyFilters($query, $criteria);

        $total = (int) (clone $query)->distinct()->count('users.user_id');
        $this->applyOrdering($query, $criteria);

        $items = $query
            ->offset(($criteria->page() - 1) * $criteria->pageSize())
            ->limit($criteria->pageSize())
            ->get($this->columns())
            ->map(static function (object $row): array {
                $item = (array) $row;
                foreach ([
                    'wallet_balance',
                    'volume_30d',
                    'trade_volume_30d',
                    'total_realized_pnl',
                    'normal_pnl',
                    'mimic_pnl',
                ] as $field) {
                    $item[$field] = (string) ($item[$field] ?? '0');
                }
                $item['nice_name'] = $item['nice_name'] ?? $item['nick_name'] ?? null;
                $item['position_count'] = 0;
                $item['order_count'] = 0;
                $item['symbol_positions'] = [];
                $item['symbol_orders'] = [];

                return $item;
            })
            ->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function applyFilters(Builder $query, UserListQuery $criteria): void
    {
        if ($criteria->keyword() !== '') {
            $keyword = $criteria->keyword();
            $query->where(static function (Builder $nested) use ($keyword): void {
                if (ctype_digit($keyword)) {
                    $nested->where('users.public_user_id', $keyword);
                }

                $method = ctype_digit($keyword) ? 'orWhere' : 'where';
                $nested->{$method}('users.username', 'like', '%' . $keyword . '%')
                    ->orWhere('users.nick_name', 'like', '%' . $keyword . '%')
                    ->orWhere('aub.agent_user_id', 'like', '%' . $keyword . '%');
            });
        }

        if ($criteria->userType() !== null && $criteria->userType() > 0) {
            $query->where('users.user_type', $criteria->userType());
        }

        if ($criteria->vipLevel() !== null && $criteria->vipLevel() > 0) {
            $query->where('users.vip_level', $criteria->vipLevel());
        }

        if ($criteria->onlineStatus() !== null && in_array($criteria->onlineStatus(), [0, 1], true)) {
            $query->where('users.online_status', $criteria->onlineStatus());
        }

        if ($criteria->createdAtStart() !== null && $criteria->createdAtStart() !== '') {
            $query->where('users.created_at', '>=', $criteria->createdAtStart());
        }

        if ($criteria->createdAtEndExclusive() !== null && $criteria->createdAtEndExclusive() !== '') {
            $query->where('users.created_at', '<', $criteria->createdAtEndExclusive());
        }

        $includedUserIds = $criteria->includedPlatformUserIds();
        if ($includedUserIds !== null) {
            if ($includedUserIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('users.user_id', $includedUserIds);
            }
        }

        if ($criteria->excludedPlatformUserIds() !== []) {
            $query->whereNotIn('users.user_id', $criteria->excludedPlatformUserIds());
        }
    }

    private function applyOrdering(Builder $query, UserListQuery $criteria): void
    {
        $walletBalance = $this->connection->raw('COALESCE(asset_summary.wallet_balance, 0)');
        $volume = $this->connection->raw('COALESCE(volume_summary.volume_30d, 0)');
        $totalPnl = $this->connection->raw('COALESCE(latest_pnl.total_realized_pnl, 0)');
        $normalPnl = $this->connection->raw(
            'CASE WHEN users.user_type = ' . self::USER_TYPE_LIVE .
            ' THEN COALESCE(latest_pnl.total_realized_pnl, 0) ELSE 0 END'
        );
        $internalPnl = $this->connection->raw(
            'CASE WHEN users.user_type = ' . self::USER_TYPE_INTERNAL .
            ' THEN COALESCE(latest_pnl.total_realized_pnl, 0) ELSE 0 END'
        );
        $columns = [
            'public_user_id' => 'users.public_user_id',
            'user_id' => 'users.public_user_id',
            'wallet_balance' => $walletBalance,
            'volume_30d' => $volume,
            'trade_volume_30d' => $volume,
            'vip_level' => 'users.vip_level',
            'normal_pnl' => $normalPnl,
            'mimic_pnl' => $internalPnl,
            'total_realized_pnl' => $totalPnl,
            'created_at' => 'users.created_at',
            'last_login_at' => 'users.last_login_at',
        ];

        if ($criteria->orderBy() === 'last_login_at') {
            $query->orderByRaw('CASE WHEN users.last_login_at IS NULL THEN 1 ELSE 0 END ASC');
        }

        $query->orderBy($columns[$criteria->orderBy()] ?? 'users.created_at', $criteria->orderDirection())
            ->orderBy('users.public_user_id', 'asc');
    }

    /** @return array<int, string|\Illuminate\Database\Query\Expression> */
    private function columns(): array
    {
        return [
            'users.public_user_id AS user_id',
            'users.username',
            'users.nick_name',
            'users.nick_name AS nice_name',
            'users.user_type',
            'users.agent_id',
            'users.vip_level',
            'users.risk_status',
            'users.risk_label',
            'users.online_status',
            'users.last_login_ip',
            'users.last_login_at',
            'users.created_at',
            'users.updated_at',
            'aub.agent_user_id',
            $this->connection->raw('COALESCE(asset_summary.wallet_balance, 0) AS wallet_balance'),
            $this->connection->raw('COALESCE(volume_summary.volume_30d, 0) AS volume_30d'),
            $this->connection->raw('COALESCE(volume_summary.volume_30d, 0) AS trade_volume_30d'),
            $this->connection->raw('COALESCE(latest_pnl.total_realized_pnl, 0) AS total_realized_pnl'),
            $this->connection->raw(
                'CASE WHEN users.user_type = ' . self::USER_TYPE_LIVE .
                ' THEN COALESCE(latest_pnl.total_realized_pnl, 0) ELSE 0 END AS normal_pnl'
            ),
            $this->connection->raw(
                'CASE WHEN users.user_type = ' . self::USER_TYPE_INTERNAL .
                ' THEN COALESCE(latest_pnl.total_realized_pnl, 0) ELSE 0 END AS mimic_pnl'
            ),
        ];
    }
}
