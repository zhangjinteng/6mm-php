<?php

declare(strict_types=1);

namespace SixMm\Shared\UserDetails;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;
use SixMm\Shared\Contracts\UserDataScope;

final class UserDetailQueryService
{
    private const ACTIVE_BINDING_STATUSES = ['BOUND', 'LOCKED'];

    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function findByPublicUserId(
        int|string $publicUserId,
        UserDataScope $scope
    ): ?UserDetail {
        $normalizedUserId = trim((string) $publicUserId);
        if ($normalizedUserId === '' || !ctype_digit($normalizedUserId)) {
            return null;
        }

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
            ->where('users.public_user_id', $normalizedUserId);

        $scope->apply($query, 'users.agent_id');

        $row = $query->first([
            'users.user_id AS platform_user_id',
            'users.public_user_id AS user_id',
            'users.username',
            'users.nick_name',
            'users.nick_name AS nice_name',
            'users.user_type',
            'users.vip_level',
            'users.online_status',
            'users.last_login_ip',
            'users.last_login_at',
            'users.created_at',
            'users.updated_at',
            'aub.agent_user_id',
        ]);

        if ($row === null) {
            return null;
        }

        $attributes = (array) $row;
        $platformUserId = (int) ($attributes['platform_user_id'] ?? 0);
        unset($attributes['platform_user_id']);

        return new UserDetail($platformUserId, $attributes);
    }
}
