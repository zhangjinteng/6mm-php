<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class GrpcOptions
{
    public static function forService(string $service,string $identity,array $options=[]): array
    {
        if (isset($options['grpc_call_invoker'])) throw new \LogicException('Existing gRPC invoker must be explicitly composed');
        return $options+['grpc_call_invoker'=>new CallInvoker(static fn()=>app(WriteJournal::class),$service,$identity)];
    }
}
