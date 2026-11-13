<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

class RecordedUnaryCall extends \Grpc\UnaryCall
{
    private ?array $ticket = null;
    private bool $finished = false;
    public function __construct($channel,private string $rpcMethod,$deserialize,array $options,
        private WriteJournal|\Closure $journal,private string $service,private string $identity,private string $endpoint)
    { parent::__construct($channel,$rpcMethod,$deserialize,$options); }
    public function start($data,array $metadata=[],array $options=[])
    {
        if (WriteMethodPolicy::isWrite($this->service,$this->rpcMethod)) {
            if ($this->journal instanceof \Closure) $this->journal = ($this->journal)();
            $this->ticket = $this->journal->begin($this->service,$this->rpcMethod,$this->identity,$this->endpoint,$data,$metadata);
        }
        try { parent::start($data,$metadata,$options); }
        catch (\Throwable $error) { $this->record(null,null,$error); throw $error; }
    }
    public function wait()
    {
        try { [$response,$status] = parent::wait(); }
        catch (\Throwable $error) { $this->record(null,null,$error); throw $error; }
        $this->record($response,$status);
        return [$response,$status];
    }
    private function record(mixed $response,mixed $status,?\Throwable $error=null): void
    {
        if ($this->finished) return;
        $this->finished = true;
        if ($this->ticket !== null) $this->journal->finish($this->ticket,$response,$status,$error);
    }
}
