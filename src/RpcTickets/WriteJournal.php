<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class WriteJournal
{
    private string $fingerprintKey;
    public function __construct(private TicketStore $store, private string $application, private ?\Closure $context = null, private ?\Closure $alert = null, ?string $fingerprintKey = null)
    {
        if (!in_array($application,['platform','agent'],true)) throw new \InvalidArgumentException('RPC ticket application must be platform or agent');
        $this->fingerprintKey=$fingerprintKey ?: random_bytes(32);
    }

    public function begin(string $service,string $method,string $identity,string $endpoint,mixed $request,array $metadata=[]): ?array
    {
        $method = basename($method);
        if (!WriteMethodPolicy::isWrite($service,$method)) return null;
        // Compare the actual payload, not its redacted display. The keyed digest does not expose low-entropy secrets.
        $raw=is_object($request)&&method_exists($request,'serializeToJsonString')?$request->serializeToJsonString():json_encode($request,JSON_THROW_ON_ERROR);
        $fingerprint=hash_hmac('sha256',$service.':'.$method.':'.$raw,$this->fingerprintKey);
        $request = TicketPayload::normalize($request);
        if (strlen(json_encode($request,JSON_THROW_ON_ERROR)) > 2097152) throw new \RuntimeException('RPC 小票请求过大，写请求未发送');
        $id = bin2hex(random_bytes(16));
        $commandIds = [];
        $collect = function($data) use (&$collect,&$commandIds): void {
            if (!is_array($data)) return;
            foreach ($data as $key=>$value) {
                if ($key === 'command_id' && is_scalar($value)) $commandIds[] = (string)$value;
                elseif (is_array($value)) $collect($value);
            }
        };
        $collect($request);
        $context = $this->context ? ($this->context)() : [];
        $safeMetadata = array_intersect_key($metadata,array_flip(['x-trace-id','x-operator-id','x-agent-id','x-tk-service']));
        $managed = OperationContext::current();
        $operation = $managed ?? ($commandIds ? $fingerprint : $id);
        return $this->store->create([
            'schema_version'=>1,'kind'=>'rpc_write','id'=>$id,'operation_id'=>$operation,'attempt_no'=>0,
            'application'=>$this->application,'service'=>$service,'method'=>$method,'service_identity'=>$identity,
            'context'=>TicketPayload::normalize($context),'metadata'=>TicketPayload::normalize($safeMetadata),
            'scope'=>array_replace(array_fill_keys(['home_site_id','agent_id','user_id','owner_id','owner_type'],null),
                array_intersect_key((array)$request,array_flip(['home_site_id','agent_id','user_id','owner_id','owner_type']))),
            'command_ids'=>$commandIds,'endpoint'=>$endpoint,'request'=>$request,'response'=>null,
            'rpc_state'=>'prepared','grpc_code'=>null,'business_codes'=>[],'error'=>null,
            'business_managed'=>$managed !== null,'started_at'=>microtime(true),'finished_at'=>null,'duration_ms'=>null,
            'business_completed_at'=>null,'expires_at'=>null,
        ]);
    }

    /** Post-send journal failures must never turn a successful financial RPC into a retryable failure. */
    public function finish(?array $ticket,mixed $response,mixed $status,?\Throwable $error=null): void
    {
        if ($ticket === null) return;
        try {
            $data = TicketPayload::normalize($response);
            $code = is_object($status) ? ($status->code ?? null) : ($status['code'] ?? null);
            $codes = [];
            $walk = function($value) use (&$walk,&$codes): void {
                if (!is_array($value)) return;
                foreach ($value as $key=>$item) {
                    if (in_array($key,['reject_code','rpc_code','error_code','result_code'],true) && is_scalar($item) && (string)$item !== '') $codes[] = (string)$item;
                    elseif ($key === 'base_response' && is_array($item) && isset($item['code'])) $codes[] = (string)$item['code'];
                    elseif (is_array($item)) $walk($item);
                }
            };
            $walk($data);
            $batchCodes = [];
            if (is_array($data) && isset($data['results']) && is_array($data['results'])) {
                foreach ($data['results'] as $item) {
                    if (is_array($item)) $batchCodes[] = (string)($item['reject_code'] ?? $item['rpc_code'] ?? $item['base_response']['code'] ?? '0');
                }
            }
            $bad = array_values(array_filter($codes,static fn($c)=>!in_array($c,['0','OK','SUCCESS','ERROR_CODE_UNSPECIFIED'],true)));
            $state = $error !== null || $code === null ? 'unknown' : ((int)$code === 0 ? ($response === null ? 'unknown' : 'confirmed')
                : (in_array((int)$code,[3,5,6,7,9,12,16],true) ? 'rejected' : 'unknown'));
            if ($state === 'confirmed' && $bad) {
                $state = count($bad) < count($codes) || in_array('0',$batchCodes,true) ? 'partial' : 'rejected';
                if (in_array('-1',$bad,true) || in_array('ERROR_CODE_OUTCOME_UNKNOWN',$bad,true)) $state = 'unknown';
            }
            if ($state === 'confirmed' && is_array($data) && ((int)($data['failed_count'] ?? 0) > 0)) {
                $state = (int)($data['success_count'] ?? 0) > 0 ? 'partial' : 'rejected';
            }
            if ($state === 'confirmed' && is_array($data) && (($data['success'] ?? true) === false
                || in_array(strtoupper((string)($data['status'] ?? '')),['PENDING','PROCESSING','PREPARED','IN_PROGRESS','ACCEPTED'],true))) $state = 'unknown';
            if ((int)$code === 0 && $ticket['service'] === 'funding' && isset($data['result_status'])) {
                $state = match ((string)$data['result_status']) {
                    '1','COMMAND_RESULT_STATUS_SUCCEEDED'=>'confirmed',
                    '2','COMMAND_RESULT_STATUS_REJECTED'=>'rejected',
                    default=>'unknown',
                };
            }
            $ticket = array_replace($ticket,[
                'response'=>TicketPayload::response($data),'rpc_state'=>$state,'grpc_code'=>$code === null ? null : (int)$code,
                'business_codes'=>$codes,'error'=>$error ? ['class'=>get_class($error),'code'=>(string)$error->getCode()] : null,
                'finished_at'=>microtime(true),'duration_ms'=>round((microtime(true)-$ticket['started_at'])*1000,3),
            ]);
            if ($state === 'rejected' || ($state === 'confirmed' && !$ticket['business_managed'])) $ticket['expires_at'] = time()+TicketStore::RETENTION_SECONDS;
            $this->store->save($ticket);
            if ($state === 'confirmed' && !$ticket['business_managed']) $this->store->completeOperation($this->application,$ticket['operation_id']);
        } catch (\Throwable $failure) {
            if ($this->alert) { try { ($this->alert)(['ticket_id'=>$ticket['id'],'event'=>'rpc_ticket_finish_failed']); } catch (\Throwable) {} }
        }
    }
    public function completeOperation(string $operationId): void { $this->store->completeOperation($this->application,$operationId); }
}
