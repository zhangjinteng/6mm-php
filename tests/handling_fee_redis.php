<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use SixMm\Shared\HandlingFees\{HandlingFeeOperationStore, RedisHandlingFeeOperationStore};

$socket = getenv('HANDLING_FEE_TEST_REDIS_SOCKET');
if (!$socket) { echo "Fee Redis integration skipped (isolated test socket not provided)\n"; exit(0); }
$native = new Redis();
$native->connect($socket);
// Run only on the isolated, socket-only test server, never on a shared service.
if ((int) $native->info('server')['tcp_port'] !== 0) throw new RuntimeException('Use a socket-only isolated Redis');
$connection = new class($native) {
    public function __construct(private Redis $redis) {}
    public function eval(string $script, int $count, mixed ...$args): mixed { return $this->redis->eval($script, $args, $count); }
    public function get(string $key): mixed { return $this->redis->get($key); }
    public function smembers(string $key): array { return $this->redis->smembers($key); }
};
$prefix = 'fee-test:{operations}:'.bin2hex(random_bytes(8));
$clock = time();
$store = new RedisHandlingFeeOperationStore(fn()=>$connection,$prefix,static function() use (&$clock): int { return $clock; });
$otherHost = new RedisHandlingFeeOperationStore(fn()=>$connection,$prefix,static function() use (&$clock): int { return $clock; });
$checks = 0;
$check = static function(bool $ok,string $message) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException($message); };
$operation = ['id'=>str_repeat('a',32),'home_site_id'=>10,'agent_id'=>20,'request_id'=>'same-request',
    'status'=>'prepared','payload'=>'{"command_id":"123","version":"456"}'];
$key = $prefix.':10:20:operation:'.$operation['id'];
$requestKey = $prefix.':10:20:request:'.hash('sha256',$operation['request_id']);
try {
    try { $store->save($operation); throw new LogicException('Unlocked save accepted'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'scope lock'),'Unscoped writes are rejected'); }
    $operation = $store->withScopeLock(10,20,fn()=>$store->save($operation));
    $check($native->ttl($key) === -1 && $native->ttl($requestKey) === -1,'Pending receipt and request index have no TTL');
    $check($otherHost->pending(10,20)['payload'] === $operation['payload'],'Another host reads the exact original command');
    $check($otherHost->get(10,21,$operation['id']) === null && $otherHost->byRequest(10,21,'same-request') === null,'Both scope fields isolate receipts');
    $operation = $store->withScopeLock(10,20,fn()=>$store->save(array_replace($operation,['status'=>'confirmed'])));
    $check($native->ttl($key) === -1 && $otherHost->pending(10,20)['status'] === 'confirmed','TK confirmed but local unfinished has no TTL');
    $operation = $store->withScopeLock(10,20,fn()=>$store->save(array_replace($operation,['status'=>'applied'])));
    $check($native->ttl($key) === -1 && $otherHost->pending(10,20) === null && count($otherHost->vipPending()) === 1,'VIP outstanding remains durable and indexed');
    $operation = $store->withScopeLock(10,20,fn()=>$store->save(array_replace($operation,['vip_recalculated_at'=>gmdate('Y-m-d H:i:s',$clock)])));
    $check($native->ttl($key) >= 604795 && $native->ttl($key) <= 604800 && $native->ttl($requestKey) >= 604795,'Completed receipt and request index expire in seven days');
    $check($otherHost->vipPending() === [],'Completed receipt leaves VIP index atomically');
    $expires = $operation['expires_at']; $clock += 3600;
    $operation = $store->withScopeLock(10,20,fn()=>$store->save($operation));
    $check($operation['expires_at'] === $expires && $native->ttl($key) <= 601200,'Saving a final receipt never resets retention');
    $clock = $expires+1;
    $check($otherHost->get(10,20,$operation['id']) === null && $otherHost->byRequest(10,20,'same-request') === null,'Expired records cannot be retried');
    $clock = time();
    $rejected = array_replace($operation,['id'=>str_repeat('b',32),'request_id'=>'rejected-request','status'=>'rejected']);
    unset($rejected['expires_at'],$rejected['completed_at']);
    $rejected = $store->withScopeLock(10,20,fn()=>$store->save($rejected));
    $check($native->ttl($prefix.':10:20:operation:'.$rejected['id']) >= 604795,'Explicit rejection starts seven-day retention immediately');
    $store->withScopeLock(10,20,function() use ($store,$native,$prefix,$operation,$check): void {
        $native->del($prefix.':10:20:lock');
        try { $store->save($operation); throw new LogicException('Lost lock accepted'); }
        catch (RuntimeException|RedisException $e) {
            $check(str_contains((string) $native->getLastError(),'FEE_SCOPE_LOCK_LOST'),'Lua rejects a lost scope lock');
        }
    });
    echo "Fee Redis integration: {$checks} checks passed\n";
} finally {
    foreach ($native->keys($prefix.':*') as $key) $native->del($key);
    $native->close();
}
