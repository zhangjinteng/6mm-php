<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class RpcTicketsServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TicketStore::class,static fn()=>new RedisTicketStore(
            static fn()=>IsolatedRedisConnection::connect((string)config('rpc_tickets.redis_connection','handling_fee_operations')),
            (string)config('rpc_tickets.prefix','rpc-write:{tickets}:v1')));
        $this->app->singleton(WriteJournal::class,static fn()=>new WriteJournal(app(TicketStore::class),
            (string)config('rpc_tickets.application'),
            static function():array {
                $actor = null;
                try { $actor = \Illuminate\Support\Facades\Auth::guard('api')->user(); } catch (\Throwable) {}
                $uid = null;
                if (app()->bound('request')) $uid = get_object_vars(app('request'))['uid'] ?? null;
                return ['actor_type'=>$actor ? get_class($actor) : ($uid !== null ? 'admin' : 'system'),
                    'actor_id'=>$actor ? (string)$actor->getAuthIdentifier() : ($uid !== null ? (string)$uid : null)];
            },static fn(array $event)=>\Illuminate\Support\Facades\Log::error('RPC write ticket storage failure',$event),
            (string)config('app.key')));
    }
}
