<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

final class IpInfoConfig
{
    public readonly string $token;
    public readonly string $baseUrl;
    public readonly int $timeout;
    public readonly int $cacheTtl;
    public readonly int $failureCacheTtl;

    public function __construct(
        string $token = '',
        string $baseUrl = 'https://ipinfo.io',
        int $timeout = 3,
        int $cacheTtl = 86400,
        int $failureCacheTtl = 300
    ) {
        $this->token = trim($token);
        $this->baseUrl = rtrim(trim($baseUrl), '/') ?: 'https://ipinfo.io';
        $this->timeout = max($timeout, 1);
        $this->cacheTtl = max($cacheTtl, 1);
        $this->failureCacheTtl = max($failureCacheTtl, 1);
    }
}
