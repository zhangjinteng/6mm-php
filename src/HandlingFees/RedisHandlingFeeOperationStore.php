<?php
declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use Closure;
use DomainException;
use RuntimeException;

/** The Redis connection must be shared by both hosts, persistent and non-evicting. */
final class RedisHandlingFeeOperationStore implements HandlingFeeOperationStore
{
    private array $locks = [];
    private Closure $clock;

    public function __construct(private Closure $redis, private string $prefix = 'handling-fee:{operations}:v1', ?callable $clock = null)
    {
        if (!preg_match('/^[A-Za-z0-9:{}_-]{1,160}$/D', $prefix)) throw new \InvalidArgumentException('Invalid fee Redis prefix');
        $this->clock = Closure::fromCallable($clock ?? time(...));
    }

    public function now(): int { return ($this->clock)(); }

    public function withScopeLock(int $site, int $agent, callable $callback): mixed
    {
        $key = $this->scope($site, $agent) . ':lock';
        if (isset($this->locks[$key])) return $callback();
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + 3;
        do {
            $acquired = ($this->redis)()->eval("return redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', 300) and 1 or 0", 1, $key, $token);
            if ((int) $acquired === 1) break;
            usleep(50000);
        } while (microtime(true) < $deadline);
        if ((int) $acquired !== 1) throw new DomainException('当前范围正在处理手续费操作，请稍后重试');
        $this->locks[$key] = $token;
        try { return $callback(); }
        finally {
            unset($this->locks[$key]);
            ($this->redis)()->eval("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0", 1, $key, $token);
        }
    }

    public function get(int $site, int $agent, string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        return $this->read($this->scope($site, $agent) . ':operation:' . $id, $site, $agent);
    }

    public function byRequest(int $site, int $agent, string $requestId): ?array
    {
        $id = ($this->redis)()->get($this->scope($site, $agent) . ':request:' . hash('sha256', $requestId));
        return $id ? $this->get($site, $agent, (string) $id) : null;
    }

    public function pending(int $site, int $agent): ?array
    {
        $id = ($this->redis)()->get($this->scope($site, $agent) . ':pending');
        if (!$id) return null;
        $operation = $this->get($site, $agent, (string) $id);
        if ($operation === null) throw new RuntimeException('手续费待确认记录缺失，请先人工核对 TK');
        return in_array($operation['status'], ['prepared', 'unknown', 'confirmed'], true) ? $operation : null;
    }

    public function save(array $operation): array
    {
        $site = (int) $operation['home_site_id']; $agent = (int) $operation['agent_id'];
        $scope = $this->scope($site, $agent);
        $token = $this->locks[$scope . ':lock'] ?? null;
        if ($token === null) throw new RuntimeException('Fee operations must be saved under their scope lock');
        $operation = HandlingFeeOperationRetention::apply($operation, $this->now());
        $ttl = isset($operation['expires_at']) ? max(1, (int) $operation['expires_at'] - $this->now()) : 0;
        $key = $scope . ':operation:' . $operation['id'];
        $pending = in_array($operation['status'], ['prepared', 'unknown', 'confirmed'], true);
        $dirty = $operation['status'] === 'applied' && empty($operation['vip_recalculated_at']);
        $saved = ($this->redis)()->eval(self::SAVE, 5, $key,
            $scope . ':request:' . hash('sha256', $operation['request_id']), $scope . ':pending',
            $this->prefix . ':vip-pending', $scope . ':lock',
            json_encode($operation, JSON_THROW_ON_ERROR), $operation['id'], (string) $ttl,
            $pending ? '1' : '0', $dirty ? '1' : '0', $token);
        if ((int) $saved !== 1) throw new RuntimeException('手续费 Redis 操作记录未确认保存');
        return $operation;
    }

    public function vipPending(): array
    {
        $result = [];
        foreach (($this->redis)()->smembers($this->prefix . ':vip-pending') as $key) {
            $raw = ($this->redis)()->get($key);
            if (!$raw) throw new RuntimeException('手续费 VIP 待重算记录缺失，请先人工核对');
            $operation = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if ($operation['status'] === 'applied' && empty($operation['vip_recalculated_at'])) $result[] = $operation;
        }
        return $result;
    }

    private function read(string $key, int $site, int $agent): ?array
    {
        $raw = ($this->redis)()->get($key);
        if ($raw === null || $raw === false) return null;
        $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ((int) $record['home_site_id'] !== $site || (int) $record['agent_id'] !== $agent) throw new RuntimeException('手续费记录归属异常');
        if (isset($record['expires_at']) && (int) $record['expires_at'] <= $this->now()) return null;
        return $record;
    }

    private function scope(int $site, int $agent): string
    {
        if ($site < 0 || $agent < 0 || ($site === 0 && $agent > 0)) throw new \InvalidArgumentException('Invalid fee operation scope');
        return $this->prefix . ':' . $site . ':' . $agent;
    }

    public const SAVE = <<<'LUA'
if redis.call('GET', KEYS[5]) ~= ARGV[6] then return redis.error_reply('FEE_SCOPE_LOCK_LOST') end
redis.call('SET', KEYS[1], ARGV[1])
redis.call('SET', KEYS[2], ARGV[2])
if tonumber(ARGV[3]) > 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[3])
    redis.call('EXPIRE', KEYS[2], ARGV[3])
else
    redis.call('PERSIST', KEYS[1])
    redis.call('PERSIST', KEYS[2])
end
if ARGV[4] == '1' then
    redis.call('SET', KEYS[3], ARGV[2])
elseif redis.call('GET', KEYS[3]) == ARGV[2] then
    redis.call('DEL', KEYS[3])
end
if ARGV[5] == '1' then redis.call('SADD', KEYS[4], KEYS[1]) else redis.call('SREM', KEYS[4], KEYS[1]) end
return 1
LUA;
}
