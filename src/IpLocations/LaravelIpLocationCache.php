<?php

declare(strict_types=1);

namespace SixMm\Shared\IpLocations;

use Illuminate\Contracts\Cache\Repository;

final class LaravelIpLocationCache implements IpLocationCache
{
    public function __construct(private readonly Repository $cache) {}

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->cache->put($key, $value, $ttlSeconds);
    }
}
