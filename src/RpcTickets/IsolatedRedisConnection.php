<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

/** Dedicated namespace/connection, not a separate logical DB: production supports DB0 only. */
final class IsolatedRedisConnection
{
    public static function connect(string $name): mixed
    {
        return \Illuminate\Support\Facades\Redis::connection($name);
    }
}
