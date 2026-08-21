<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

final class IpInfoHttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body
    ) {}
}
