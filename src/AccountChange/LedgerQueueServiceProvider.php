<?php

declare(strict_types=1);

namespace SixMm\Shared\AccountChange;

use Illuminate\Support\ServiceProvider;

/** Reuse the existing cross-application Redis; no additional host configuration. */
final class LedgerQueueServiceProvider extends ServiceProvider
{
    public const CONNECTION = 'account-change-ledger';
    public const QUEUE = 'account-change-ledger';
    public const REDIS_CONNECTION = 'handling_fee_operations';

    public function register(): void
    {
        // Only this logical queue is bound here. Never change queue.default,
        // queue.connections.redis or the host's Redis prefix.
        $this->app['config']->set('queue.connections.'.self::CONNECTION, [
            'driver' => 'redis',
            'connection' => self::REDIS_CONNECTION,
            'queue' => self::QUEUE,
            'retry_after' => 90, // Must exceed the job's 45 second timeout.
            'block_for' => null,
            'after_commit' => true,
        ]);
    }
}
