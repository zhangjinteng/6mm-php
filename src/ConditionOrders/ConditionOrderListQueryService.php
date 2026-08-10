<?php

declare(strict_types=1);

namespace SixMm\Shared\ConditionOrders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class ConditionOrderListQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(ConditionOrderListQuery $criteria, UserDataScope $scope): PageResult
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
            ->table('condition_orders')
            ->join('users', 'users.user_id', '=', 'condition_orders.user_id')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join): void {
                $join->on('aub.agent_id', '=', 'users.agent_id')
                    ->on('aub.platform_user_id', '=', 'users.user_id');
            })
            ->leftJoin('orders AS generated_orders', 'generated_orders.order_id', '=', 'condition_orders.generated_order_id')
            ->whereNull('condition_orders.deleted_at')
            ->whereNull('users.deleted_at');

        $scope->apply($query, 'users.agent_id');
        $this->applyPreset($query, $criteria);
        $this->applyFilters($query, $criteria);

        $total = (int) (clone $query)->distinct()->count('condition_orders.condition_id');

        $items = $query
            ->orderBy('condition_orders.created_at', 'desc')
            ->orderBy('condition_orders.condition_id', 'desc')
            ->offset(($criteria->page() - 1) * $criteria->pageSize())
            ->limit($criteria->pageSize())
            ->get([
                'condition_orders.*',
                'users.public_user_id',
                'users.username',
                'users.nick_name',
                'users.user_type AS current_user_type',
                'aub.agent_user_id',
                $this->connection->raw('COALESCE(generated_orders.filled_quantity, 0) AS filled_quantity'),
            ])
            ->map(function (object $row) use ($criteria): array {
                $item = (array) $row;
                $platformUserId = (int) $item['user_id'];
                $resolvedUserType = $criteria->lifecycle()->usesUserTypeSnapshot()
                    ? ($item['user_type'] ?? $item['current_user_type'] ?? null)
                    : ($item['current_user_type'] ?? null);

                $item['platform_user_id'] = $platformUserId;
                $item['user_id'] = isset($item['public_user_id'])
                    ? (int) $item['public_user_id']
                    : $platformUserId;
                $item['user_type'] = $resolvedUserType === null ? null : (int) $resolvedUserType;
                $item['filled_quantity'] = (string) ($item['filled_quantity'] ?? '0');
                $item['user'] = [
                    'user_id' => $item['user_id'],
                    'username' => $item['username'] ?? null,
                    'nick_name' => $item['nick_name'] ?? null,
                    'user_type' => $item['user_type'],
                    'agent_user_id' => $item['agent_user_id'] ?? null,
                ];
                unset($item['public_user_id'], $item['current_user_type']);

                return $item;
            })
            ->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function applyPreset(Builder $query, ConditionOrderListQuery $criteria): void
    {
        $query->whereIn('condition_orders.trigger_status', $criteria->lifecycle()->triggerStatuses());

        if ($criteria->kind()->closePosition() !== null) {
            $query->where('condition_orders.close_position', $criteria->kind()->closePosition());
        }

        if ($criteria->kind()->triggerTypes() !== []) {
            $query->whereIn(
                $this->connection->raw('LOWER(condition_orders.trigger_type)'),
                $criteria->kind()->triggerTypes()
            );
        }
    }

    private function applyFilters(Builder $query, ConditionOrderListQuery $criteria): void
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
                    ->orWhere('aub.agent_user_id', 'like', '%' . $keyword . '%')
                    ->orWhereRaw('CAST(condition_orders.condition_id AS TEXT) = ?', [$keyword])
                    ->orWhereRaw('CAST(condition_orders.order_id AS TEXT) = ?', [$keyword])
                    ->orWhereRaw('CAST(condition_orders.generated_order_id AS TEXT) = ?', [$keyword])
                    ->orWhere('condition_orders.client_order_id', 'like', '%' . $keyword . '%');
            });
        }

        if ($criteria->userType() !== null) {
            $column = $criteria->lifecycle()->usesUserTypeSnapshot()
                ? 'condition_orders.user_type'
                : 'users.user_type';
            $query->where($column, $criteria->userType());
        }

        if ($criteria->symbol() !== '') {
            $query->whereRaw('LOWER(condition_orders.symbol) LIKE ?', ['%' . strtolower($criteria->symbol()) . '%']);
        }

        if ($criteria->orderType() !== null) {
            $query->whereRaw('LOWER(condition_orders.trigger_type) = ?', [$criteria->orderType()]);
        }

        if ($criteria->side() !== null) {
            $query->whereRaw('LOWER(condition_orders.side) = ?', [$criteria->side()]);
        }

        if ($criteria->reduceOnly() !== null) {
            $query->where('condition_orders.reduce_only', $criteria->reduceOnly());
        }

        if ($criteria->createdAtStart() !== null) {
            $query->where('condition_orders.created_at', '>=', $criteria->createdAtStart());
        }

        if ($criteria->createdAtEndExclusive() !== null) {
            $query->where('condition_orders.created_at', '<', $criteria->createdAtEndExclusive());
        }
    }
}
