<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class IsolatedRedisConnection
{
    public static function connect(string $name): mixed
    {
        $parser = new \Illuminate\Support\ConfigurationUrlParser();
        $connection = $parser->parseConfiguration(config('database.redis.'.$name));
        foreach (config('cache.stores',[]) as $cache) {
            if (($cache['driver'] ?? '') !== 'redis') continue;
            $cached = $parser->parseConfiguration(config('database.redis.'.($cache['connection'] ?? 'default'),[]));
            if (($connection['host'] ?? '') === ($cached['host'] ?? '') && (int)($connection['port'] ?? 6379) === (int)($cached['port'] ?? 6379)
                && (int)($connection['database'] ?? 0) === (int)($cached['database'] ?? 0)) {
                throw new \RuntimeException('RPC 小票必须与普通缓存使用不同的 Redis 库或实例，避免 cache:clear 清除待确认记录');
            }
        }
        return \Illuminate\Support\Facades\Redis::connection($name);
    }
}
