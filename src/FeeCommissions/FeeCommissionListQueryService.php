<?php

declare(strict_types=1);

namespace SixMm\Shared\FeeCommissions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class FeeCommissionListQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(
        private ConnectionInterface $connection,
        private ?FeeCommissionTradeDetailProvider $tradeDetailProvider = null
    ) {
    }

    public function count(FeeCommissionListQuery $criteria, UserDataScope $scope): int
    {
        $query = $this->filteredQuery($criteria, $scope);

        return (int) $query->distinct()->count('details.id');
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(
        FeeCommissionListQuery $criteria,
        UserDataScope $scope,
        ?int $knownTotal = null
    ): PageResult {
        $query = $this->filteredQuery($criteria, $scope);
        $total = max(0, $knownTotal ?? (int) (clone $query)->distinct()->count('details.id'));

        if ($total === 0) {
            return new PageResult([], 0, $criteria->page(), $criteria->pageSize());
        }

        $this->applyOrdering($query, $criteria);
        $items = $query
            ->offset(($criteria->page() - 1) * $criteria->pageSize())
            ->limit($criteria->pageSize())
            ->get($this->columns())
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        if ($this->tradeDetailProvider !== null && $items !== []) {
            $items = $this->tradeDetailProvider->enrich($items);
        }

        $items = array_map([$this, 'normalizeRow'], $items);

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function filteredQuery(FeeCommissionListQuery $criteria, UserDataScope $scope): Builder
    {
        $query = $this->baseQuery();
        $scope->apply($query, 'details.agent_id');
        $this->applyFilters($query, $criteria);

        return $query;
    }

    private function baseQuery(): Builder
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

        return $this->connection
            ->table('agent_commission_details AS details')
            ->leftJoin('users AS commission_user', static function (JoinClause $join): void {
                $join->on('commission_user.user_id', '=', 'details.platform_user_id')
                    ->on('commission_user.agent_id', '=', 'details.agent_id');
            })
            ->leftJoinSub($bindings, 'binding', static function (JoinClause $join): void {
                $join->on('binding.agent_id', '=', 'details.agent_id')
                    ->on('binding.platform_user_id', '=', 'details.platform_user_id');
            })
            ->whereNull('commission_user.deleted_at');
    }

    private function applyFilters(Builder $query, FeeCommissionListQuery $criteria): void
    {
        if ($criteria->keyword() !== '') {
            $keyword = $criteria->keyword();
            $query->where(static function (Builder $nested) use ($keyword): void {
                if (ctype_digit($keyword)) {
                    $nested->where('commission_user.public_user_id', $keyword);
                } else {
                    $nested->whereRaw('1 = 0');
                }

                $nested->orWhereRaw('LOWER(binding.agent_user_id) LIKE ?', [
                    '%' . mb_strtolower($keyword) . '%',
                ])->orWhereRaw('CAST(details.order_id AS TEXT) = ?', [$keyword])
                    ->orWhereRaw('CAST(details.position_id AS TEXT) = ?', [$keyword])
                    ->orWhereRaw('CAST(details.trade_id AS TEXT) = ?', [$keyword]);
            });
        }

        if ($criteria->symbol() !== '') {
            $query->whereRaw('LOWER(details.symbol) LIKE ?', [
                '%' . mb_strtolower($criteria->symbol()) . '%',
            ]);
        }
        if ($criteria->marginMode() !== null) {
            $query->whereRaw('LOWER(details.margin_mode) = ?', [$criteria->marginMode()]);
        }
        if ($criteria->side() !== '') {
            $query->whereRaw('LOWER(details.side) = ?', [$criteria->side()]);
        }
        if ($criteria->roleType() !== '') {
            $query->whereRaw('LOWER(details.role_type) = ?', [$criteria->roleType()]);
        }
        if ($criteria->tradeTimeStart() !== '') {
            $query->where('details.trade_time', '>=', $criteria->tradeTimeStart());
        }
        if ($criteria->tradeTimeEndExclusive() !== '') {
            $query->where('details.trade_time', '<', $criteria->tradeTimeEndExclusive());
        }
    }

    private function applyOrdering(Builder $query, FeeCommissionListQuery $criteria): void
    {
        $column = match ($criteria->orderBy()) {
            'public_user_id', 'user_id' => 'commission_user.public_user_id',
            'order_id' => 'details.order_id',
            'position_id' => 'details.position_id',
            'trade_value' => 'details.trade_value',
            'handling_fee' => 'details.handling_fee',
            'commission_amount' => 'details.commission_amount',
            default => 'details.trade_time',
        };

        $query->orderByRaw($column . ' IS NULL ASC')
            ->orderBy($column, $criteria->orderDirection())
            ->orderBy('details.id', 'desc');
    }

    /** @return array<int, string> */
    private function columns(): array
    {
        return [
            'details.*',
            'commission_user.public_user_id',
            'commission_user.username',
            'commission_user.nick_name',
            'commission_user.user_type AS current_user_type',
            'binding.agent_user_id',
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function normalizeRow(array $item): array
    {
        foreach (['id', 'platform_user_id', 'trade_id', 'order_id', 'position_id'] as $field) {
            if (array_key_exists($field, $item) && $item[$field] !== null && $item[$field] !== '') {
                $item[$field] = (string) $item[$field];
            }
        }
        foreach (['price', 'quantity', 'trade_value', 'handling_fee', 'commission_rate', 'commission_amount'] as $field) {
            if (array_key_exists($field, $item) && $item[$field] !== null) {
                $item[$field] = (string) $item[$field];
            }
        }

        $publicUserId = $item['public_user_id'] ?? null;
        $item['user_id'] = $publicUserId !== null && $publicUserId !== ''
            ? (string) $publicUserId
            : ($item['platform_user_id'] ?? null);
        $item['user'] = $publicUserId === null && ($item['username'] ?? null) === null
            ? null
            : [
                'user_id' => $item['user_id'],
                'public_user_id' => $publicUserId === null ? null : (string) $publicUserId,
                'username' => $item['username'] ?? null,
                'nick_name' => $item['nick_name'] ?? null,
                'user_type' => $item['current_user_type'] ?? null,
                'agent_user_id' => $item['agent_user_id'] ?? null,
            ];

        unset($item['public_user_id'], $item['current_user_type']);

        return $item;
    }
}
