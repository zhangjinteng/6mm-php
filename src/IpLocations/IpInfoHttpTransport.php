<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

interface IpInfoHttpTransport
{
    /** @param list<string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout
    ): IpInfoHttpResponse;
}
