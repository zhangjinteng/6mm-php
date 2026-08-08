<?php

declare(strict_types=1);

namespace SixMm\Shared\OnlineUsers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class OnlineUserQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(OnlineUserQuery $criteria, UserDataScope $scope): PageResult
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

        $query = $this->connection
            ->table('users')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join): void {
                $join->on('aub.agent_id', '=', 'users.agent_id')
                    ->on('aub.platform_user_id', '=', 'users.user_id');
            })
            ->whereNull('users.deleted_at')
            ->where('users.online_status', 1);

        $scope->apply($query, 'users.agent_id');
        $this->applyFilters($query, $criteria);

        $total = (int) (clone $query)->distinct()->count('users.user_id');
        $this->applyOrdering($query, $criteria);

        $items = $query
            ->offset(($criteria->page() - 1) * $criteria->pageSize())
            ->limit($criteria->pageSize())
            ->get([
                'users.public_user_id AS user_id',
                'users.username',
                'users.nick_name',
                'users.nick_name AS nice_name',
                'users.user_type',
                'users.vip_level',
                'users.last_login_ip',
                'users.last_login_at',
                'users.created_at',
                'users.updated_at',
                'aub.agent_user_id',
            ])
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function applyFilters(Builder $query, OnlineUserQuery $criteria): void
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

        if ($criteria->createdAtStart() !== null && $criteria->createdAtStart() !== '') {
            $query->where('users.created_at', '>=', $criteria->createdAtStart());
        }

        if ($criteria->createdAtEndExclusive() !== null && $criteria->createdAtEndExclusive() !== '') {
            $query->where('users.created_at', '<', $criteria->createdAtEndExclusive());
        }
    }

    private function applyOrdering(Builder $query, OnlineUserQuery $criteria): void
    {
        $column = $criteria->orderBy() === 'vip_level'
            ? 'users.vip_level'
            : 'users.last_login_at';

        if ($criteria->orderBy() === 'last_login_at') {
            $query->orderByRaw('CASE WHEN users.last_login_at IS NULL THEN 1 ELSE 0 END ASC');
        }

        $query->orderBy($column, $criteria->orderDirection())
            ->orderBy('users.public_user_id', 'asc');
    }
}
