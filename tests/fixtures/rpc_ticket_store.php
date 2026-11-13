<?php
declare(strict_types=1);
use SixMm\Shared\RpcTickets\TicketStore;

final class MemoryRpcTicketStore implements TicketStore
{
    public array $tickets=[];
    public bool $failCreate=false;
    public bool $failSave=false;
    private array $counts=[];
    public function create(array $ticket): array
    {
        if ($this->failCreate) throw new RuntimeException('Fixture storage unavailable');
        $key=$ticket['application'].':'.$ticket['operation_id'];
        $ticket['attempt_no']=($this->counts[$key]??0)+1;$this->counts[$key]=$ticket['attempt_no'];
        return $this->tickets[$ticket['id']]=$ticket;
    }
    public function save(array $ticket): void
    {
        if ($this->failSave) throw new RuntimeException('Fixture storage unavailable');
        $this->tickets[$ticket['id']]=$ticket;
    }
    public function completeOperation(string $application,string $operationId): void
    {
        foreach($this->tickets as &$ticket) if($ticket['application']===$application&&$ticket['operation_id']===$operationId&&$ticket['expires_at']===null){
            $ticket['business_completed_at']=time();$ticket['expires_at']=time()+self::RETENTION_SECONDS;
        }
    }
}
