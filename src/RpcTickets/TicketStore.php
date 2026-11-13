<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

interface TicketStore
{
    public const RETENTION_SECONDS = 604800;
    public function create(array $ticket): array;
    public function save(array $ticket): void;
    public function completeOperation(string $application, string $operationId): void;
}
