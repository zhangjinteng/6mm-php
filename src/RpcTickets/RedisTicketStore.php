<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class RedisTicketStore implements TicketStore
{
    public function __construct(private \Closure $redis, private string $prefix = 'rpc-write:{tickets}:v1')
    {
        if (!preg_match('/^[A-Za-z0-9:{}_-]{1,160}$/D', $prefix)) throw new \InvalidArgumentException('Invalid RPC ticket prefix');
    }
    private function key(string $id): string { return $this->prefix.':ticket:'.$id; }
    private function operation(array $ticket): string { return $this->prefix.':operation:'.hash('sha256',$ticket['application'].':'.$ticket['operation_id']); }
    public function create(array $ticket): array
    {
        unset($ticket['attempt_no']);
        $ticket['attempt_no'] = (int) ($this->redis)()->eval(self::CREATE, 4,
            $this->key($ticket['id']),$this->operation($ticket).':count',$this->operation($ticket).':tickets',$this->prefix.':pending',
            json_encode($ticket,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),$ticket['id'],(string) time());
        if ($ticket['attempt_no'] < 1) throw new \RuntimeException('RPC 小票未保存，写请求未发送');
        return $ticket;
    }
    public function save(array $ticket): void
    {
        for ($attempt=0;$attempt<5;$attempt++) {
            $raw=($this->redis)()->get($this->key($ticket['id']));
            if (!$raw) throw new \RuntimeException('RPC 小票缺失');
            $current=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if (isset($current['expires_at'])) {
                $ticket['expires_at']=min($ticket['expires_at']??PHP_INT_MAX,$current['expires_at']);
                $ticket['business_completed_at']=$current['business_completed_at'];
            }
            $ttl=isset($ticket['expires_at'])?max(1,$ticket['expires_at']-time()):0;
            $saved=($this->redis)()->eval(self::SAVE,4,$this->key($ticket['id']),$this->operation($ticket).':count',
                $this->operation($ticket).':tickets',$this->prefix.':pending',
                json_encode($ticket,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),$ticket['id'],(string)$ttl,$raw);
            if ((int)$saved===1) return;
        }
        throw new \RuntimeException('RPC 小票并发更新，请核对原记录');
    }
    public function completeOperation(string $application, string $operationId): void
    {
        $op = $this->operation(['application'=>$application,'operation_id'=>$operationId]);
        foreach (($this->redis)()->smembers($op.':tickets') as $id) {
            for ($attempt=0;$attempt<5;$attempt++) {
                $raw=($this->redis)()->get($this->key($id));
                if (!$raw) break;
                $ticket=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                if (isset($ticket['expires_at'])) break;
                $ticket['business_completed_at']=time();$ticket['expires_at']=time()+self::RETENTION_SECONDS;
                $saved=($this->redis)()->eval(self::COMPLETE_ONE,2,$this->key($id),$this->prefix.':pending',
                    $raw,json_encode($ticket,JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),(string)self::RETENTION_SECONDS,$id);
                if ((int)$saved===1) break;
                if ($attempt===4) throw new \RuntimeException('RPC 小票业务确认发生并发更新');
            }
        }
        ($this->redis)()->eval(self::EXPIRE_INDEX,3,$op.':tickets',$op.':count',$this->prefix.':pending',(string)self::RETENTION_SECONDS);
    }
    private const CREATE = <<<'LUA'
local n = redis.call('INCR', KEYS[2])
redis.call('PERSIST', KEYS[2])
local value = '{"attempt_no":'..n..','..string.sub(ARGV[1],2)
redis.call('SET', KEYS[1], value)
redis.call('SADD', KEYS[3], ARGV[2])
redis.call('PERSIST', KEYS[3])
redis.call('ZADD', KEYS[4], ARGV[3], ARGV[2])
return n
LUA;
    private const SAVE = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[4] then return 0 end
redis.call('SET', KEYS[1], ARGV[1])
if tonumber(ARGV[3]) > 0 then
  redis.call('EXPIRE', KEYS[1], ARGV[3])
  redis.call('ZREM', KEYS[4], ARGV[2])
  local pending = false
  for _, id in ipairs(redis.call('SMEMBERS', KEYS[3])) do
    if redis.call('ZSCORE', KEYS[4], id) then pending = true end
  end
  if not pending then
    redis.call('EXPIRE', KEYS[2], ARGV[3])
    redis.call('EXPIRE', KEYS[3], ARGV[3])
  end
else
  redis.call('PERSIST', KEYS[1])
end
return 1
LUA;
    private const COMPLETE_ONE = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
redis.call('SET', KEYS[1], ARGV[2], 'EX', ARGV[3])
redis.call('ZREM', KEYS[2], ARGV[4])
return 1
LUA;
    private const EXPIRE_INDEX = <<<'LUA'
for _, id in ipairs(redis.call('SMEMBERS', KEYS[1])) do
  if redis.call('ZSCORE', KEYS[3], id) then return 0 end
end
redis.call('EXPIRE', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[2], ARGV[1])
return 1
LUA;
}
