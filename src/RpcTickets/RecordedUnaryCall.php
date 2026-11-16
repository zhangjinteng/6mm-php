<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

use SixMm\Shared\RpcLogging\TkRpcLogger;

class RecordedUnaryCall extends \Grpc\UnaryCall
{
    private ?array $ticket = null;
    private bool $finished = false;
    private ?TkRpcLogger $rpcLog;
    public function __construct($channel,private string $rpcMethod,$deserialize,array $options,
        private WriteJournal|\Closure $journal,private string $service,private string $identity,private string $endpoint,
        ?TkRpcLogger $rpcLog = null)
    {
        parent::__construct($channel,$rpcMethod,$deserialize,$options);
        $this->rpcLog = $service === 'tk' ? ($rpcLog ?? new TkRpcLogger($identity,$rpcMethod,$endpoint,$options)) : null;
    }
    public function start($data,array $metadata=[],array $options=[])
    {
        $this->rpcLog?->start($data);
        try {
            if (WriteMethodPolicy::isWrite($this->service,$this->rpcMethod)) {
                if ($this->journal instanceof \Closure) $this->journal = ($this->journal)();
                $this->ticket = $this->journal->begin($this->service,$this->rpcMethod,$this->identity,$this->endpoint,$data,$metadata);
            }
            parent::start($data,$metadata,$options);
        } catch (\Throwable $error) {
            $this->rpcLog?->exception($error,'start');
            $this->record(null,null,$error);
            throw $error;
        }
    }
    public function wait()
    {
        try { [$response,$status] = parent::wait(); }
        catch (\Throwable $error) {
            $this->rpcLog?->exception($error,'wait');
            $this->record(null,null,$error);
            throw $error;
        }
        $this->record($response,$status);
        $this->rpcLog?->response($response,$status);
        return [$response,$status];
    }
    private function record(mixed $response,mixed $status,?\Throwable $error=null): void
    {
        if ($this->finished) return;
        $this->finished = true;
        if ($this->ticket !== null) $this->journal->finish($this->ticket,$response,$status,$error);
    }
}
