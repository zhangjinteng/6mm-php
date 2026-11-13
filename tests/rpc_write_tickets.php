<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$hostAutoload = dirname(__DIR__,2).'/agent_admin/vendor/autoload.php';
if (is_file($hostAutoload)) require $hostAutoload;
spl_autoload_register(static function($class):void {
    $prefix='SixMm\\Shared\\RpcTickets\\';
    if(str_starts_with($class,$prefix))require dirname(__DIR__).'/src/RpcTickets/'.substr($class,strlen($prefix)).'.php';
},true,true);

use SixMm\Shared\RpcTickets\{OperationContext,TicketPayload,TicketStore,WriteJournal,WriteMethodPolicy};

$store = new class implements TicketStore {
    public array $tickets=[];
    public bool $failCreate=false;
    public bool $failSave=false;
    public array $counts=[];
    public function create(array $t): array { if($this->failCreate)throw new RuntimeException('offline'); $t['attempt_no']=($this->counts[$t['operation_id']]??0)+1; $this->counts[$t['operation_id']]=$t['attempt_no']; return $this->tickets[$t['id']]=$t; }
    public function save(array $t): void { if($this->failSave)throw new RuntimeException('offline'); $this->tickets[$t['id']]=$t; }
    public function completeOperation(string $app,string $id): void { foreach($this->tickets as &$t) if($t['application']===$app&&$t['operation_id']===$id&&$t['expires_at']===null){$t['expires_at']=time()+self::RETENTION_SECONDS;$t['business_completed_at']=time();} }
};
$checks=0;
$check=static function(bool $value,string $why)use(&$checks):void{++$checks;if(!$value)throw new RuntimeException($why);};
$alerts=[];
$journal=new WriteJournal($store,'agent',fn()=>['actor_id'=>'12'],static function($event)use(&$alerts){$alerts[]=$event;});
foreach(class_exists(\TradeKernel\Sdk\RpcMethodClassifier::class) ? ['GetAccount','GetFundingToContractResult','GetSubaccountWalletTransfer','GetRiskNotificationConfig','ListSymbolTradingConfigs','ValidatePlaceOrder'] : [] as$method){
    $check($journal->begin('tk',$method,'agent_admin','offline',[])===null,'Query/validation has no ticket: '.$method);
}
$check($store->tickets===[],'Queries do not touch ticket storage');
$request=['command_id'=>'1234567890123456789','home_site_id'=>'100','agent_id'=>'20','apiSecret'=>'do-not-store','nested'=>['authorization'=>'Bearer do-not-store','password'=>'private']];
$ticket=$journal->begin('funding','/asset.AssetService/CreditFunding','agent_admin','offline',$request,['authorization'=>['Bearer do-not-store'],'x-trace-id'=>['trace-test']]);
$check($ticket['schema_version']===1&&$ticket['application']==='agent'&&$ticket['command_ids']===['1234567890123456789'],'Stable envelope and lossless command ID');
$check(!str_contains(json_encode($ticket),'do-not-store')&&!str_contains(json_encode($ticket),'private'),'Secrets are never persisted');
$journal->finish($ticket,['rejectCode'=>0],(object)['code'=>0]);
$saved=$store->tickets[$ticket['id']];
$check($saved['rpc_state']==='confirmed'&&$saved['expires_at']-time()===604800,'Confirmed call retained seven days');
$retry=$journal->begin('funding','CreditFunding','agent_admin','offline',$request);
$check($retry['operation_id']===$ticket['operation_id']&&$retry['id']!==$ticket['id']&&$retry['attempt_no']===2,'Attempts link to one exact command but have separate tickets');
$changedSecret=$journal->begin('funding','CreditFunding','agent_admin','offline',array_replace($request,['apiSecret'=>'a-different-secret']));
$check($changedSecret['operation_id']!==$ticket['operation_id'],'Different secret payloads never merge after redaction');
$journal->finish($retry,null,(object)['code'=>4]);
$check($store->tickets[$retry['id']]['rpc_state']==='unknown'&&$store->tickets[$retry['id']]['expires_at']===null,'Write timeout remains pending without TTL');
$rejected=$journal->begin('condition','CancelConditionOrder','admin','offline',['user_id'=>12]);
$journal->finish($rejected,['baseResponse'=>['code'=>5001]],(object)['code'=>0]);
$check($store->tickets[$rejected['id']]['rpc_state']==='rejected'&&$store->tickets[$rejected['id']]['expires_at']!==null,'Condition business refusal is not transport success');
$batch=$journal->begin('funding','BatchApplyFundingMutations','agent_admin','offline',['items'=>[['command_id'=>'111']]]);
$journal->finish($batch,['results'=>[[],['rejectCode'=>6003]]],(object)['code'=>0]);
$check($store->tickets[$batch['id']]['rpc_state']==='partial'&&$store->tickets[$batch['id']]['expires_at']===null,'Partial batch success is retained for confirmation');
$managed=OperationContext::run('fee-operation',fn()=>$journal->begin('funding','BatchApplyFundingMutations','agent_admin','offline',['items'=>[['command_id'=>'222']]]));
$journal->finish($managed,[],(object)['code'=>0]);
$check($store->tickets[$managed['id']]['expires_at']===null&&OperationContext::current()===null,'Business-managed receipt waits for business completion and scope is restored');
$journal->completeOperation('fee-operation');
$check($store->tickets[$managed['id']]['expires_at']-time()===604800,'Business completion starts seven-day retention');
$store->failCreate=true;
try{$journal->begin('funding','CreditFunding','agent_admin','offline',[]);throw new LogicException('Write sent without receipt');}catch(RuntimeException){$check(true,'Pre-send storage failure blocks write');}
$store->failCreate=false;$store->failSave=true;
$journal->finish($retry,[],(object)['code'=>0]);
$check(count($alerts)===1&&$store->tickets[$retry['id']]['rpc_state']==='unknown','Post-send storage error preserves receipt and does not alter RPC result');
$check(TicketPayload::normalize(['apiKey'=>'private','amount'=>'0.000000000000000001'])===['api_key'=>'[REDACTED]','amount'=>'0.000000000000000001'],'Decimals remain exact while key names are uniform');
$check(TicketPayload::normalize(['PASSWORD'=>'private','API_KEY'=>'private'])===['password'=>'[REDACTED]','api_key'=>'[REDACTED]'],'Uppercase secrets are redacted too');
$check(TicketPayload::response(['large'=>str_repeat('a',1048577)])['truncated']===true,'Oversized response stored as summary');
$store->failSave=false;
$accepted=$journal->begin('funding','CreateAccountTransfer','agent_admin','offline',[]);
$journal->finish($accepted,['resultStatus'=>'COMMAND_RESULT_STATUS_ACCEPTED','resultCode'=>'OK'],(object)['code'=>0]);
$check($store->tickets[$accepted['id']]['rpc_state']==='unknown'&&$store->tickets[$accepted['id']]['expires_at']===null,'Accepted funding command is not mistaken for completed business');
$businessRejected=$journal->begin('funding','CreateFundingAdjustment','agent_admin','offline',[]);
$journal->finish($businessRejected,['resultStatus'=>'COMMAND_RESULT_STATUS_REJECTED','resultCode'=>'INSUFFICIENT_BALANCE'],(object)['code'=>0]);
$check($store->tickets[$businessRejected['id']]['rpc_state']==='rejected','Funding business rejection is recorded separately from gRPC success');
foreach(['prediction'=>['SaveOwnerConfig','ListOrders'],'funding'=>['CreditFunding','GetFundingAccount'],'hedging'=>['RunHedge','CheckExchangeConnection'],'tronpay'=>['Transfer','GetTransfer'],'condition'=>['CancelConditionOrder','GetOrder']]as$s=>$pair){
    $check(WriteMethodPolicy::isWrite($s,$pair[0])&&!WriteMethodPolicy::isWrite($s,$pair[1]),'Write-only policy for '.$s);
}
echo "RPC write tickets: {$checks} checks passed\n";
