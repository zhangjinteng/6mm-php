<?php

declare(strict_types=1);

namespace SixMm\Shared\MarginChangeLogs;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class MarginChangeLogListQueryService
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(MarginChangeLogListQuery $criteria, UserDataScope $scope): PageResult
    {
        $query = $this->baseQuery();
        $scope->apply($query, 'logs.agent_id');
        $this->applyFilters($query, $criteria);

        $total = (int) (clone $query)->count('logs.id');
        $this->applyOrdering($query, $criteria);

        $items = $query
            ->offset(($criteria->page() - 1) * $criteria->pageSize())
            ->limit($criteria->pageSize())
            ->get($this->columns())
            ->map(static function (object $row): array {
                $item = (array) $row;
                foreach (['delta_amount', 'balance_before', 'balance_after'] as $field) {
                    $item[$field] = (string) ($item[$field] ?? '0');
                }
                $item['transfer_amount'] = $item['transfer_amount'] === null
                    ? null
                    : (string) $item['transfer_amount'];
                $item['nice_name'] = $item['nice_name'] ?? null;
                $item['user'] = [
                    'platform_user_id' => $item['platform_user_id'] ?? null,
                    'user_id' => $item['user_id'] ?? null,
                    'username' => $item['username'] ?? null,
                    'nice_name' => $item['nice_name'],
                    'user_type' => $item['user_type'] ?? null,
                ];

                return $item;
            })
            ->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function baseQuery(): Builder
    {
        return $this->connection
            ->table('agent_account_change_log AS logs')
            ->leftJoin('users', static function (JoinClause $join): void {
                $join->on('users.user_id', '=', 'logs.platform_user_id')
                    ->on('users.agent_id', '=', 'logs.agent_id');
            })
            ->leftJoin('agent_transfer_orders AS transfer_orders', static function (JoinClause $join): void {
                $join->on('transfer_orders.agent_id', '=', 'logs.agent_id')
                    ->on('transfer_orders.agent_order_no', '=', 'logs.order_no');
            })
            ->whereNull('logs.deleted_at')
            ->whereNull('users.deleted_at');
    }

    private function applyFilters(Builder $query, MarginChangeLogListQuery $criteria): void
    {
        if ($criteria->userId() !== '') {
            $query->where('users.public_user_id', $criteria->userId());
        }
        if ($criteria->username() !== '') {
            $query->whereRaw('LOWER(users.username) LIKE ?', [
                '%' . mb_strtolower($criteria->username()) . '%',
            ]);
        }
        if ($criteria->userType() !== null) {
            $query->where('logs.user_type', $criteria->userType());
        }
        if (!$criteria->includeZeroAmount()) {
            $query->where('logs.delta_amount', '!=', 0);
        }
        if ($criteria->bizTypes() !== []) {
            $query->whereIn(
                $this->connection->raw('LOWER(logs.biz_type)'),
                $criteria->bizTypes()
            );
        }
        if ($criteria->createdAtStart() !== '') {
            $query->where('logs.created_at', '>=', $criteria->createdAtStart());
        }
        if ($criteria->createdAtEndExclusive() !== '') {
            $query->where('logs.created_at', '<', $criteria->createdAtEndExclusive());
        }
    }

    private function applyOrdering(Builder $query, MarginChangeLogListQuery $criteria): void
    {
        $column = match ($criteria->orderBy()) {
            'biz_type' => 'logs.biz_type',
            'delta_amount' => 'logs.delta_amount',
            'balance_before' => 'logs.balance_before',
            'balance_after' => 'logs.balance_after',
            default => 'logs.created_at',
        };

        $query->orderByRaw($column . ' IS NULL ASC')
            ->orderBy($column, $criteria->orderDirection())
            ->orderBy('logs.id', 'desc');
    }

    /** @return array<int, string> */
    private function columns(): array
    {
        return [
            'logs.id',
            'logs.agent_id',
            'logs.platform_user_id',
            'logs.user_type',
            'logs.currency',
            'logs.biz_type',
            'logs.biz_id',
            'logs.delta_amount',
            'logs.balance_before',
            'logs.balance_after',
            'logs.order_no',
            'logs.created_at',
            'logs.updated_at',
            'users.public_user_id AS user_id',
            'users.username',
            'users.nick_name AS nice_name',
            'transfer_orders.amount AS transfer_amount',
        ];
    }
}
