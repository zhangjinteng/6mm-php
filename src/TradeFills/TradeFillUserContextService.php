<?php

declare(strict_types=1);

namespace SixMm\Shared\TradeFills;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;

final class TradeFillUserContextService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return array<int, int> */
    public function scopedPlatformUserIds(UserDataScope $scope, ?int $userType = null): array
    {
        $query = $this->connection
            ->table('users')
            ->whereNull('users.deleted_at');
        $scope->apply($query, 'users.agent_id');
        if (in_array($userType, [1, 2, 3], true)) {
            $query->where('users.user_type', $userType);
        }

        return $query
            ->orderBy('users.user_id')
            ->pluck('users.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    public function matchingPlatformUserIds(string $keyword, UserDataScope $scope): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }

        $query = $this->identityQuery()->whereNull('users.deleted_at');
        $scope->apply($query, 'users.agent_id');
        $query->where(static function (Builder $nested) use ($keyword): void {
            if (ctype_digit($keyword)) {
                $nested->where('users.public_user_id', $keyword);
            }
            $method = ctype_digit($keyword) ? 'orWhere' : 'where';
            $nested->{$method}('users.username', 'like', '%' . $keyword . '%')
                ->orWhere('users.nick_name', 'like', '%' . $keyword . '%')
                ->orWhere('aub.agent_user_id', 'like', '%' . $keyword . '%');
        });

        return $query
            ->distinct()
            ->orderBy('users.user_id')
            ->pluck('users.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function hydrateRows(array $rows, UserDataScope $scope): array
    {
        $platformUserIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['platform_user_id'] ?? $row['user_id'] ?? 0),
            $rows
        ))));
        if ($platformUserIds === []) {
            return [];
        }

        $usersQuery = $this->identityQuery()
            ->whereNull('users.deleted_at')
            ->whereIn('users.user_id', $platformUserIds);
        $scope->apply($usersQuery, 'users.agent_id');
        $users = $usersQuery->get([
            'users.user_id AS platform_user_id',
            'users.public_user_id AS user_id',
            'users.username',
            'users.nick_name',
            'users.user_type',
            'users.agent_id',
            'aub.agent_user_id',
        ])->keyBy(static fn (object $row): string => (string) $row->platform_user_id);

        $result = [];
        foreach ($rows as $row) {
            $platformUserId = (int) ($row['platform_user_id'] ?? $row['user_id'] ?? 0);
            $user = $users->get((string) $platformUserId);
            if ($platformUserId <= 0 || $user === null) {
                continue;
            }

            $sourceUserType = (int) ($row['user_type'] ?? 0);
            $resolvedUserType = in_array($sourceUserType, [1, 2, 3], true)
                ? $sourceUserType
                : (int) ($user->user_type ?? 0);
            $niceName = $user->nick_name ?? null;

            $row['platform_user_id'] = $platformUserId;
            $row['user_id'] = $user->user_id;
            $row['public_user_id'] = $user->user_id;
            $row['username'] = $user->username ?? null;
            $row['nice_name'] = $niceName;
            $row['user_type'] = $resolvedUserType > 0 ? $resolvedUserType : null;
            $row['agent_id'] = $user->agent_id;
            $row['agent_user_id'] = $user->agent_user_id ?? null;
            $row['user'] = [
                'platform_user_id' => $platformUserId,
                'user_id' => $user->user_id,
                'public_user_id' => $user->user_id,
                'username' => $user->username ?? null,
                'nick_name' => $niceName,
                'nice_name' => $niceName,
                'user_type' => $row['user_type'],
                'agent_id' => $user->agent_id,
                'agent_user_id' => $row['agent_user_id'],
            ];
            $result[] = $row;
        }

        return $result;
    }

    private function identityQuery(): Builder
    {
        $bindings = $this->connection->table('agent_user_bindings')
            ->select([
                'agent_id',
                'platform_user_id',
                $this->connection->raw('MAX(agent_user_id) AS agent_user_id'),
            ])
            ->whereIn('bind_status', self::ACTIVE_BINDING_STATUSES)
            ->groupBy('agent_id', 'platform_user_id');

        return $this->connection->table('users')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join): void {
                $join->on('aub.agent_id', '=', 'users.agent_id')
                    ->on('aub.platform_user_id', '=', 'users.user_id');
            });
    }
}
