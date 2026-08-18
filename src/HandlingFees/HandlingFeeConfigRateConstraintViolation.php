<?php

declare(strict_types=1);

namespace SixMm\Shared\HandlingFees;

use DomainException;

final class HandlingFeeConfigRateConstraintViolation extends DomainException
{
    public const PLATFORM_FLOOR = 'platform_floor';
    public const LOWER_TIER_FLOOR = 'lower_tier_floor';
    public const UPPER_TIER_CEILING = 'upper_tier_ceiling';

    public function __construct(
        private string $field,
        private string $rule,
        private string $boundaryRate
    ) {
        parent::__construct(sprintf(
            'Handling-fee %s violates %s boundary %s.',
            $this->field,
            $this->rule,
            $this->boundaryRate
        ));
    }

    public function field(): string
    {
        return $this->field;
    }

    public function rule(): string
    {
        return $this->rule;
    }

    public function boundaryRate(): string
    {
        return $this->boundaryRate;
    }
}
