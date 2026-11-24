<?php

declare(strict_types=1);

namespace SixMm\Shared\HistoryOrders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;

final class HistoryOrderUserContextService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return array<int, int> */
    public function scopedPlatformUserIds(UserDataScope $scope): array
    {
        $query = $this->connection
            ->table('users')
            ->whereNull('users.deleted_at');
        $scope->apply($query, 'users.agent_id');

        return $query
            ->orderBy('users.user_id')
            ->pluck('users.user_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    public function platformUserIdsForType(UserDataScope $scope, ?int $userType): array
    {
        if (!in_array($userType, [1, 2, 3], true)) {
            return [];
        }

        $query = $this->connection
            ->table('users')
            ->whereNull('users.deleted_at')
            ->where('users.user_type', $userType);
        $scope->apply($query, 'users.agent_id');

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

        $query = $this->identityQuery()
            ->whereNull('users.deleted_at');
        $scope->apply($query, 'users.agent_id');
        $query->where(static function (Builder $nested) use ($keyword): void {
            if (ctype_digit($keyword)) {
                $nested->where('users.public_user_id', $keyword);
            }

            $method = ctype_digit($keyword) ? 'orWhere' : 'where';
            $nested->{$method}('aub.agent_user_id', 'like', '%' . $keyword . '%');
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
        $users = $usersQuery
            ->get([
                'users.user_id AS platform_user_id',
                'users.public_user_id AS user_id',
                'users.username',
                'users.nick_name',
                'users.user_type',
                'users.agent_id',
                'aub.agent_user_id',
            ])
            ->keyBy(static fn (object $row): string => (string) $row->platform_user_id);

        $result = [];
        foreach ($rows as $row) {
            $platformUserId = (int) ($row['platform_user_id'] ?? $row['user_id'] ?? 0);
            $user = $users->get((string) $platformUserId);
            if ($platformUserId <= 0 || $user === null) {
                continue;
            }

            $snapshotUserType = (int) ($row['user_type'] ?? 0);
            $resolvedUserType = in_array($snapshotUserType, [1, 2, 3], true)
                ? $snapshotUserType
                : (int) ($user->user_type ?? 0);
            $niceName = $user->nick_name ?? null;

            $row['platform_user_id'] = $platformUserId;
            $row['user_id'] = $user->user_id;
            $row['username'] = $user->username ?? null;
            $row['nice_name'] = $niceName;
            $row['user_type'] = $resolvedUserType > 0 ? $resolvedUserType : null;
            $row['agent_id'] = $user->agent_id;
            $row['agent_user_id'] = $user->agent_user_id ?? null;
            $row['user'] = array_merge(is_array($row['user'] ?? null) ? $row['user'] : [], [
                'platform_user_id' => $platformUserId,
                'user_id' => $user->user_id,
                'public_user_id' => $user->user_id,
                'username' => $user->username ?? null,
                'nick_name' => $niceName,
                'nice_name' => $niceName,
                'user_type' => $row['user_type'],
                'agent_id' => $user->agent_id,
                'agent_user_id' => $row['agent_user_id'],
            ]);
            $result[] = $row;
        }

        return $result;
    }

    /** Resolve identity keywords, while the CK owner predicate remains the data access boundary. */
    public function matchingHistoricalPlatformUserIds(string $keyword): array
    {
        if (($uid = \SixMm\Shared\UserDetails\PortraitUidFilter::parse($keyword)) !== null) {
            return \SixMm\Shared\UserDetails\PortraitUidFilter::ids($this->connection, $uid);
        }
        $keyword=trim($keyword);
        if ($keyword==='') return [];
        $bindings=$this->connection->table('agent_user_bindings')
            ->whereIn('bind_status',self::ACTIVE_BINDING_STATUSES)->where('agent_user_id','like','%'.$keyword.'%');
        return $this->connection->table('users')->where(static function (Builder $q) use ($keyword,$bindings): void {
            $q->where('username','like','%'.$keyword.'%')->orWhere('nick_name','like','%'.$keyword.'%')
                ->orWhereIn('user_id',$bindings->select('platform_user_id'));
            if (ctype_digit($keyword)) $q->orWhere('public_user_id',$keyword);
        })->distinct()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** Enrich only a CK-authorized page. Current ownership must not rewrite or discard history. */
    public function hydrateHistoricalRows(array $rows): array
    {
        $ids=array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['platform_user_id'] ?? $row['user_id'] ?? 0), $rows
        ), static fn (int $id): bool => $id>0)));
        if ($ids===[]) return [];
        $users=$this->connection->table('users')->whereIn('user_id',$ids)
            ->get(['user_id','public_user_id','username','nick_name','user_type'])->keyBy('user_id');
        $agentIds=array_values(array_unique(array_map(static fn (array $row): int => (int) ($row['agent_id'] ?? 0),$rows)));
        $bindings=$this->connection->table('agent_user_bindings')->whereIn('platform_user_id',$ids)
            ->whereIn('agent_id',$agentIds)->whereIn('bind_status',self::ACTIVE_BINDING_STATUSES)
            ->select('agent_id','platform_user_id')->selectRaw('MAX(agent_user_id) AS agent_user_id')
            ->groupBy('agent_id','platform_user_id')->get()->keyBy(static fn (object $r): string => $r->agent_id.':'.$r->platform_user_id);
        foreach ($rows as &$row) {
            $id=(int) ($row['platform_user_id'] ?? $row['user_id'] ?? 0);
            $user=$users->get($id);
            $agent=$row['agent_id'] ?? null;
            $external=$bindings->get((string) $agent.':'.$id)->agent_user_id ?? null;
            $snapshotType=(int) ($row['user_type'] ?? 0);
            $type=in_array($snapshotType,[1,2,3],true) ? $snapshotType : ($user->user_type ?? null);
            $identity=['platform_user_id'=>$id,'user_id'=>$user->public_user_id ?? null,
                'public_user_id'=>$user->public_user_id ?? null,'username'=>$user->username ?? null,
                'nick_name'=>$user->nick_name ?? null,'nice_name'=>$user->nick_name ?? null,'user_type'=>$type,
                'agent_id'=>$agent,'home_site_id'=>$row['home_site_id'] ?? null,'agent_user_id'=>$external];
            $row['platform_user_id']=$id;
            $row['user_id']=$identity['user_id'];
            $row['username']=$identity['username'];
            $row['nice_name']=$identity['nice_name'];
            $row['user_type']=$type;
            $row['agent_user_id']=$external;
            $row['user']=array_merge(is_array($row['user'] ?? null) ? $row['user'] : [],$identity);
        }
        unset($row);
        return $rows;
    }

    private function identityQuery(): Builder
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
            ->table('users')
            ->leftJoinSub($bindings, 'aub', static function (JoinClause $join): void {
                $join->on('aub.agent_id', '=', 'users.agent_id')
                    ->on('aub.platform_user_id', '=', 'users.user_id');
            });
    }
}
