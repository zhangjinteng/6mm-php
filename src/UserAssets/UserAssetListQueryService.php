<?php

declare(strict_types=1);

namespace SixMm\Shared\UserAssets;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Pagination\PageResult;

final class UserAssetListQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return PageResult<array<string, mixed>> */
    public function search(UserAssetListQuery $criteria, UserDataScope $scope): PageResult
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
            ->table('user_assets AS assets')
            ->join('users', 'users.user_id', '=', 'assets.user_id')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join): void {
                $join->on('aub.agent_id', '=', 'users.agent_id')
                    ->on('aub.platform_user_id', '=', 'users.user_id');
            })
            ->whereNull('assets.deleted_at')
            ->whereNull('users.deleted_at');

        $scope->apply($query, 'users.agent_id');
        $this->applyFilters($query, $criteria);

        $total = (int) (clone $query)->distinct()->count('assets.ua_id');
        $this->applyOrdering($query, $criteria);

        $items = $query
            ->offset(($criteria->page() - 1) * $criteria->pageSize())
            ->limit($criteria->pageSize())
            ->get($this->columns())
            ->map(static function (object $row): array {
                $item = (array) $row;
                foreach (['wallet_balance', 'frozen_balance', 'realized_pnl'] as $field) {
                    $item[$field] = (string) ($item[$field] ?? '0');
                }
                $item['nice_name'] = $item['nice_name'] ?? $item['nick_name'] ?? null;

                return $item;
            })
            ->all();

        return new PageResult($items, $total, $criteria->page(), $criteria->pageSize());
    }

    private function applyFilters(Builder $query, UserAssetListQuery $criteria): void
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

        if ($criteria->userType() !== null) {
            $query->where('users.user_type', $criteria->userType());
        }

        if ($criteria->agentId() !== null) {
            $query->where('users.agent_id', $criteria->agentId());
        }
    }

    private function applyOrdering(Builder $query, UserAssetListQuery $criteria): void
    {
        $columns = [
            'ua_id' => 'assets.ua_id',
            'platform_user_id' => 'assets.user_id',
            'user_id' => 'users.public_user_id',
            'public_user_id' => 'users.public_user_id',
            'uid' => 'users.public_user_id',
        ];

        $query->orderBy($columns[$criteria->orderBy()] ?? 'assets.ua_id', $criteria->orderDirection());
        if ($criteria->orderBy() !== 'ua_id') {
            $query->orderBy('assets.ua_id', 'desc');
        }
    }

    /** @return array<int, string> */
    private function columns(): array
    {
        return [
            'assets.ua_id',
            'assets.user_id AS platform_user_id',
            'assets.asset_type',
            'assets.wallet_balance',
            'assets.frozen_balance',
            'assets.realized_pnl',
            'assets.version',
            'assets.created_at',
            'assets.updated_at',
            'users.public_user_id AS user_id',
            'users.username',
            'users.nick_name',
            'users.nick_name AS nice_name',
            'users.user_type',
            'users.agent_id',
            'aub.agent_user_id',
        ];
    }
}
