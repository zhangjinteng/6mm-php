<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

/** Synchronous PHP call scope; always restored, including failed RPCs. */
final class OperationContext
{
    private static array $stack = [];
    public static function current(): ?string { return self::$stack === [] ? null : end(self::$stack); }
    public static function run(string $operationId, callable $call): mixed
    {
        self::$stack[] = $operationId;
        try { return $call(); } finally { array_pop(self::$stack); }
    }
}
