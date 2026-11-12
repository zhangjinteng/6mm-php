<?php

declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use DomainException;

final class HandlingFeeConfigWriteGuard
{
    public function __construct(private int $writableAgentId = 0, private int $writableHomeSiteId = 0)
    {
    }

    public function allows(int|string|null $agentId, int|string|null $homeSiteId = 0): bool
    {
        return filter_var($agentId, FILTER_VALIDATE_INT) !== false
            && (int) $agentId === $this->writableAgentId
            && filter_var($homeSiteId, FILTER_VALIDATE_INT) !== false
            && (int)$homeSiteId === $this->writableHomeSiteId;
    }

    public function assertAllows(int|string|null $agentId, int|string|null $homeSiteId = 0): void
    {
        if (!$this->allows($agentId, $homeSiteId)) {
            throw new DomainException('The handling-fee configuration is outside the writable scope.');
        }
    }
}
