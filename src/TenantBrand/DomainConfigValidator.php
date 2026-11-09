<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

final class DomainConfigValidator
{
    public static function normalize(mixed $domains): array
    {
        if (!is_array($domains) || count($domains) > 20 || !array_is_list($domains)) {
            throw new DomainConfigException('访问域名最多 20 个');
        }
        if ($domains === []) throw new DomainConfigException('请保留至少一个站点域名');
        $result = [];
        foreach ($domains as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['domain', 'is_primary']) !== []
                || !is_string($row['domain'] ?? null) || !is_bool($row['is_primary'] ?? null)) {
                throw new DomainConfigException('域名及主域名标记格式不正确');
            }
            $domain = strtolower(trim($row['domain']));
            if (str_ends_with($domain, '.')) $domain = substr($domain, 0, -1);
            if (!preg_match('/^(?=.{1,253}$)(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)
                || filter_var($domain, FILTER_VALIDATE_IP)) {
                throw new DomainConfigException('请输入有效域名，不包含协议、端口或路径；国际化域名请使用 Punycode');
            }
            if (isset($result[$domain])) throw new DomainConfigException('域名不能重复');
            $result[$domain] = ['domain' => $domain, 'is_primary' => $row['is_primary']];
        }
        ksort($result);
        $rows = array_values($result);
        if ($rows !== [] && count(array_filter($rows, fn ($row) => $row['is_primary'])) !== 1) {
            throw new DomainConfigException('有访问域名时，必须且只能指定一个主域名');
        }
        return $rows;
    }
}
