<?php
declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

/** Durable command journal; business fee rows remain in the existing SQL table. */
interface HandlingFeeOperationStore
{
    public const RETENTION_SECONDS = 7 * 86400;

    public function now(): int;
    public function withScopeLock(int $site, int $agent, callable $callback): mixed;
    public function get(int $site, int $agent, string $id): ?array;
    public function byRequest(int $site, int $agent, string $requestId): ?array;
    public function pending(int $site, int $agent): ?array;
    public function save(array $operation): array;
    /** Applied operations remain durable until VIP recalculation is acknowledged. */
    public function vipPending(): array;
}
