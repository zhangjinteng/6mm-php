<?php
declare(strict_types=1);

namespace SixMm\Shared\UserDetails;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\Contracts\UserDataScope;

/** Exact UID mode of the existing list filters. Owner predicates still apply to every result. */
final class PortraitUidFilter
{
    public static function parse(string $keyword): ?string
    {
        return preg_match('/^uid:([1-9][0-9]{0,18})$/D', $keyword, $match)
            && bccomp($match[1], (string) PHP_INT_MAX) <= 0 ? $match[1] : null;
    }

    public static function ids(ConnectionInterface $db, string $uid, ?UserDataScope $scope = null): array
    {
        $query = $db->table('users')->whereNull('users.deleted_at')->where('users.public_user_id', $uid);
        $scope?->apply($query, 'users.agent_id');
        return $query->pluck('users.user_id')->map(static fn ($id): int => (int) $id)->all();
    }
}
