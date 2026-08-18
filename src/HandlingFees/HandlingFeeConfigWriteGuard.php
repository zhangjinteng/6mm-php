<?php

declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use DomainException;

final class HandlingFeeConfigWriteGuard
{
    public function __construct(private int $writableAgentId = 0)
    {
    }

    public function allows(int|string|null $agentId): bool
    {
        return filter_var($agentId, FILTER_VALIDATE_INT) !== false
            && (int) $agentId === $this->writableAgentId;
    }

    public function assertAllows(int|string|null $agentId): void
    {
        if (!$this->allows($agentId)) {
            throw new DomainException('Only the platform handling-fee configuration can be modified.');
        }
    }
}
