<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class CallInvoker extends \Grpc\DefaultCallInvoker
{
    private array $targets = [];
    public function __construct(private WriteJournal|\Closure $journal,private string $service,private string $identity) {}
    public function createChannelFactory($hostname,$opts)
    {
        $channel = parent::createChannelFactory($hostname,$opts);
        $this->targets[spl_object_id($channel)] = $hostname;
        return $channel;
    }
    public function UnaryCall($channel,$method,$deserialize,$options)
    {
        return new RecordedUnaryCall($channel,$method,$deserialize,$options,$this->journal,$this->service,$this->identity,
            $this->targets[spl_object_id($channel)] ?? '');
    }
}
