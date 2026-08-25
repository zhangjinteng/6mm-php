<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

enum PredictionRuleStatus: string
{
    case ALL = 'all';
    case ENABLED = 'enabled';
    case DISABLED = 'disabled';

    public function matches(bool $enabled): bool
    {
        return match ($this) {
            self::ALL => true,
            self::ENABLED => $enabled,
            self::DISABLED => !$enabled,
        };
    }
}
