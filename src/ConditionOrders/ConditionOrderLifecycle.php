<?php

declare(strict_types=1);

namespace SixMm\Shared\ConditionOrders;

enum ConditionOrderLifecycle: string
{
    case CURRENT = 'current';
    case HISTORY = 'history';
    case ALL = 'all';

    /** @return int[] */
    public function triggerStatuses(): array
    {
        return match ($this) {
            self::CURRENT => ConditionOrderStatus::current(),
            self::HISTORY => ConditionOrderStatus::history(),
            self::ALL => ConditionOrderStatus::all(),
        };
    }

    public function usesUserTypeSnapshot(): bool
    {
        return $this === self::HISTORY;
    }
}
