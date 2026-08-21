<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

interface IpInfoClient
{
    /** @return array<string, array<string, mixed>|null> */
    public function lookupMany(array $ips, IpInfoConfig $config): array;
}
