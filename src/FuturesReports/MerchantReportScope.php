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

    /**
     * Account ID comes from authentication, never request query parameters.
     * Published merchant reports belong to the root merchant ID, not its site.
     * The optional deployment argument and siteId metadata are retained for API
     * compatibility only; neither may prevent an authenticated merchant reading its rows.
     */
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
        if ($merchant === null) {
            throw new InvalidArgumentException('登录账号所属主商户不存在或已删除');
        }
        return new self((string) $merchant->id, (string) ($merchant->home_site_id ?? ''));
    }
}
