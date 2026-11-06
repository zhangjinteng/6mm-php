<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use Illuminate\Database\Query\Builder;

/** Synchronize one site's gateway routes inside the locked publication transaction. */
final class PublishedDomainMap
{
    public static function names(Builder $table, string $siteId): array
    {
        $names = (clone $table)->where('site_id', $siteId)->pluck('domain')->all();
        sort($names, SORT_STRING);
        return $names;
    }

    public static function sync(Builder $table, string $siteId, array $desired): void
    {
        $current = self::names($table, $siteId);
        $add = array_values(array_diff($desired, $current));
        $remove = array_values(array_diff($current, $desired));
        if ($add && (clone $table)->whereIn('domain', $add)->where('site_id', '<>', $siteId)->exists()) {
            throw new DomainConfigException('域名已在其他站点的发布映射中使用');
        }
        if ($remove) (clone $table)->where('site_id', $siteId)->whereIn('domain', $remove)->delete();
        if ($add) $table->insert(array_map(fn ($domain) => ['domain' => $domain, 'site_id' => $siteId], $add));
    }
}
