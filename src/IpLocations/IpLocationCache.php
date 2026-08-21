<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

interface IpLocationCache
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds): void;
}
