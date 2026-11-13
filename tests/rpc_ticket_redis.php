<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SixMm\Shared\RpcTickets\{OperationContext,RedisTicketStore,WriteJournal};
$socket=getenv('RPC_TICKET_TEST_REDIS_SOCKET');
if(!$socket){echo "RPC ticket Redis integration skipped (isolated test socket not provided)\n";exit(0);}
$redis=new Redis();$redis->connect($socket);
if((int)$redis->info('server')['tcp_port']!==0)throw new RuntimeException('Use an isolated socket-only Redis');
$connection=new class($redis){
    public function __construct(private Redis $redis){}
    public function eval(string $script,int $n,mixed ...$args):mixed{return $this->redis->eval($script,$args,$n);}
    public function get(string $key):mixed{return $this->redis->get($key);}
    public function smembers(string $key):array{return $this->redis->smembers($key);}
};
$prefix='test-rpc:{tickets}:'.bin2hex(random_bytes(8));
$store=new RedisTicketStore(fn()=>$connection,$prefix);
$agent=new WriteJournal($store,'agent');$platform=new WriteJournal($store,'platform');
$checks=0;$check=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok)throw new RuntimeException($why);};
$read=fn($t)=>json_decode($redis->get($prefix.':ticket:'.$t['id']),true,512,JSON_THROW_ON_ERROR);
$ttl=fn($t)=>$redis->ttl($prefix.':ticket:'.$t['id']);
try{
    $check($agent->begin('funding','GetFundingBalance','agent_admin','offline',[])===null&&$redis->keys($prefix.':*')===[],'Queries create no Redis keys');
    $t=$agent->begin('funding','CreditFunding','agent_admin','offline',['command_id'=>'9007199254740993','amount'=>'0.000000000000000001']);
    $check($ttl($t)===-1&&$read($t)['attempt_no']===1,'Prepared write is durable, with correct attempt number');
    $agent->finish($t,null,(object)['code'=>4]);
    $check($ttl($t)===-1&&$read($t)['rpc_state']==='unknown','Unknown response has no TTL');
    $next=$agent->begin('funding','CreditFunding','agent_admin','offline',['command_id'=>'9007199254740993','amount'=>'0.000000000000000001']);
    $check($read($next)['attempt_no']===2&&$next['operation_id']===$t['operation_id'],'Original command retry gets linked attempt');
    $agent->finish($next,[],(object)['code'=>0]);
    $check($ttl($t)>=604795&&$ttl($next)>=604795&&$ttl($next)<=604800,'Confirmed original-command retry closes old unknown receipt for seven days');
    $expiry=$read($t)['expires_at'];$agent->completeOperation($t['operation_id']);
    $check($read($t)['expires_at']===$expiry,'Repeated completion does not extend receipt expiry');
    $managed=OperationContext::run('managed-fee',fn()=>$agent->begin('funding','CreditFunding','agent_admin','offline',[]));
    $agent->finish($managed,[],(object)['code'=>0]);
    $check($ttl($managed)===-1,'Managed RPC success waits for business completion');
    $foreign=OperationContext::run('managed-fee',fn()=>$platform->begin('funding','CreditFunding','admin','offline',[]));
    $platform->finish($foreign,[],(object)['code'=>0]);
    $agent->completeOperation('managed-fee');
    $check($ttl($managed)>=604795&&$ttl($foreign)===-1,'Application identity isolates operation completion');
    $check(is_array(json_decode($redis->get($prefix.':ticket:'.$managed['id']))->command_ids),'Business completion preserves JSON array shape');
    $expiry=$read($managed)['expires_at'];
    $agent->finish($managed,null,(object)['code'=>4]);
    $check($read($managed)['expires_at']===$expiry&&$ttl($managed)>0,'Late attempt result cannot reopen or extend completed business receipt');
    $partial=$agent->begin('funding','BatchApplyFundingMutations','agent_admin','offline',['command_id'=>'1']);
    $agent->finish($partial,['results'=>[[],['reject_code'=>6003]]],(object)['code'=>0]);
    $check($ttl($partial)===-1&&$read($partial)['rpc_state']==='partial','Partly accepted batch stays pending');
    $refusal=$agent->begin('funding','CreditFunding','agent_admin','offline',[]);
    $agent->finish($refusal,null,(object)['code'=>7]);
    $check($ttl($refusal)>=604795&&$read($refusal)['rpc_state']==='rejected','Explicit transport refusal expires after seven days');
    $check($read($next)['request']['command_id']==='9007199254740993'&&$read($next)['request']['amount']==='0.000000000000000001','Redis keeps ID and decimal precision');
    echo "RPC ticket Redis: {$checks} checks passed\n";
}finally{foreach($redis->keys($prefix.':*')as$key)$redis->del($key);$redis->close();}
