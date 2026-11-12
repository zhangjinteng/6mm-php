<?php
declare(strict_types=1);
namespace SixMm\Shared\HandlingFees;

use DomainException;
use Illuminate\Database\ConnectionInterface;

final class HandlingFeeScope
{
    /** Legacy agent-only callers resolve the main merchant's persisted site, never guess site zero. */
    public static function site(ConnectionInterface $db, ?int $site, int $agent): int
    {
        if ($site === null) {
            if ($agent === 0) return 0;
            $owner=$db->table('agents')->where('id',$agent)->whereNull('deleted_at')->first(['home_site_id','pid']);
            if (!$owner || (int)$owner->pid !== 0) throw new DomainException('手续费配置必须属于有效主商户');
            $site=(int)$owner->home_site_id;
        }
        if ($site < 0 || $agent < 0 || ($agent > 0 && $site === 0)) throw new DomainException('无效的手续费配置归属');
        return $site;
    }
}
