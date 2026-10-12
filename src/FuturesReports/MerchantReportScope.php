<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class MerchantReportScope
{
    private function __construct(public readonly string $merchantId, public readonly string $siteId)
    {
    }

    /** Account ID comes from authentication, never request query parameters. */
    public static function forAccount(ConnectionInterface $database, string $accountId, ?string $deploymentSiteId = null): self
    {
        if (!ReportUserScope::positiveId($accountId)) {
            throw new InvalidArgumentException('登录商户身份无效');
        }
        $table = ($database->getDriverName() === 'pgsql' ? 'public.' : '') . 'agents';
        $account = $database->table($table)->where('id', $accountId)->whereNull('deleted_at')
            ->first(['id', 'pid', 'home_site_id']);
        if ($account === null) {
            throw new InvalidArgumentException('登录商户不存在或已删除');
        }
        $merchant = (string) $account->pid === '0' ? $account : $database->table($table)
            ->where('id', (string) $account->pid)->where('pid', 0)->whereNull('deleted_at')
            ->first(['id', 'pid', 'home_site_id']);
        $site = (string) ($merchant->home_site_id ?? '');
        $accountSite = (string) ($account->home_site_id ?? '');
        if ($merchant === null || !ReportUserScope::positiveId($site)
            || ($accountSite !== '' && $accountSite !== '0' && $accountSite !== $site)
            || ($deploymentSiteId !== null && $deploymentSiteId !== '' && $deploymentSiteId !== $site)) {
            throw new InvalidArgumentException('登录商户的白标站点范围无效或不匹配');
        }
        return new self((string) $merchant->id, $site);
    }
}
