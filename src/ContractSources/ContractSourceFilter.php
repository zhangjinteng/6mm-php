<?php

declare(strict_types=1);

namespace SixMm\Shared\ContractSources;

use InvalidArgumentException;

final class ContractSourceFilter
{
    public function __construct(
        public readonly string $sourceType = '',
        public readonly ?int $agentId = null,
        public readonly ?int $tenantId = null
    ) {
        if (!in_array($sourceType, ['', 'platform', 'merchant', 'white_label'], true)) {
            throw new InvalidArgumentException('无效的来源类型');
        }
        if (($agentId !== null && ($agentId <= 0 || !in_array($sourceType, ['', 'merchant'], true)))
            || ($tenantId !== null && ($tenantId <= 0 || $sourceType !== 'white_label'))
            || ($agentId !== null && $tenantId !== null)) {
            throw new InvalidArgumentException('无效的来源名称条件');
        }
    }

    public function isActive(): bool
    {
        return $this->sourceType !== '' || $this->agentId !== null || $this->tenantId !== null;
    }
}
