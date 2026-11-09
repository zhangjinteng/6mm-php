<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\Connection;
use LogicException;

final class SiteContentSpaceInitializer
{
    /** The caller authorizes the site and owns the transaction on this connection. */
    public static function ensure(Connection $db, int $siteId, string $actorType, int $actorId, string $actorName): int
    {
        if ($db->transactionLevel() < 1) {
            throw new LogicException('Site content space initialization requires an active transaction.');
        }
        $db->select('SELECT pg_advisory_xact_lock(60429, ?)', [$siteId]);
        $spaces = $db->table('cms.spaces');
        $id = (clone $spaces)->where('space_type', 'site')->where('home_site_id', $siteId)->value('id');

        return (int) ($id ?? $spaces->insertGetId([
            'space_type' => 'site', 'home_site_id' => $siteId, 'name' => '站点内容',
            'created_by_type' => $actorType, 'created_by_id' => $actorId,
            'updated_by_type' => $actorType, 'updated_by_id' => $actorId, 'updated_by_name' => $actorName,
        ]));
    }
}
