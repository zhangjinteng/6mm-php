<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$host=dirname(__DIR__,2).'/agent_admin/vendor/autoload.php';
if(!is_file($host)){echo "Native RPC adapter skipped (host gRPC fixture unavailable)\n";exit(0);}
require $host;
// Verify the candidate source, even before rebuilding the host artifact.
spl_autoload_register(static function($class):void{
    $prefix='SixMm\\Shared\\RpcTickets\\';
    if(str_starts_with($class,$prefix))require dirname(__DIR__).'/src/RpcTickets/'.substr($class,strlen($prefix)).'.php';
},true,true);
require __DIR__.'/fixtures/rpc_ticket_store.php';
use SixMm\Shared\RpcTickets\{RecordedUnaryCall,WriteJournal};
use TradeKernel\Sdk\Generated\Message\{AccountChangeRequest,AccountChangeResponse,GetAccountRequest};
$store=new MemoryRpcTicketStore();$journal=new WriteJournal($store,'agent');$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void{++$checks;if(!$ok)throw new RuntimeException($why);};
$channel=new Grpc\Channel('127.0.0.1:1',['credentials'=>Grpc\ChannelCredentials::createInsecure()]);
$response=new AccountChangeResponse();
$make=static function(string $method,?Closure $resolver=null)use($channel,$journal,$response):array{
    $call=new RecordedUnaryCall($channel,'/TradeKernel/'.$method,[AccountChangeResponse::class,'decode'],['timeout'=>1],$resolver??$journal,'tk','agent_admin','offline');
    $transport=new class($response){
        public array $batches=[];public function __construct(private $response){}
        public function startBatch(array $batch):object{
            $this->batches[]=$batch;
            return isset($batch[Grpc\OP_SEND_MESSAGE])?(object)[]:(object)['message'=>$this->response->serializeToString(),'metadata'=>[],
                'status'=>(object)['code'=>0,'details'=>'','metadata'=>[]]];
        }
    };
    (new ReflectionProperty(Grpc\UnaryCall::class,'call'))->setValue($call,$transport);
    return [$call,$transport];
};
try{
    [$call,$transport]=$make('AccountChange');
    $request=new AccountChangeRequest(['command_id'=>'1234567890123456789','user_id'=>'9007199254740993']);
    $call->start($request,['x-tk-service'=>['agent_admin'],'authorization'=>['Bearer fixture-secret']],['flags'=>0]);
    [$actual,$status]=$call->wait();
    $ticket=array_values($store->tickets)[0];
    $check(count($store->tickets)===1&&$ticket['rpc_state']==='confirmed','Real UnaryCall start/wait emits one write receipt');
    $check($request->serializeToString()===$transport->batches[0][Grpc\OP_SEND_MESSAGE]['message'],'Protobuf request unchanged');
    $check($transport->batches[0][Grpc\OP_SEND_INITIAL_METADATA]['authorization']===['Bearer fixture-secret']&&!str_contains(json_encode($ticket),'fixture-secret'),'Authorization sent unchanged but absent from receipt');
    $check($ticket['command_ids']===['1234567890123456789']&&$ticket['request']['user_id']==='9007199254740993','Actual protobuf command ID, not SDK synthetic ID, recorded');
    [$query]=$make('GetAccount',static fn()=>throw new LogicException('Query resolved journal'));
    $query->start(new GetAccountRequest(['user_id'=>'9007199254740993']));$query->wait();
    $check(count($store->tickets)===1,'Query neither resolves journal nor writes Redis');
    $store->failCreate=true;[$blocked,$transport]=$make('AccountChange');
    try{$blocked->start($request);throw new LogicException('Write was sent');}catch(RuntimeException){$check($transport->batches===[],'Missing receipt blocks native send');}
    $store->failCreate=false;$store->failSave=true;[$finish]=$make('AccountChange');$finish->start($request);[$actual]=$finish->wait();
    $check($actual instanceof AccountChangeResponse,'Post-send journal failure does not change the RPC response');
    echo "Native RPC tickets: {$checks} checks passed\n";
}finally{$channel->close();}
