<?php

declare(strict_types=1);

namespace SixMm\Shared\ConditionOrders;

enum ConditionOrderKind: string
{
    case ALL = 'all';
    case CONDITION = 'condition';
    case TP_SL = 'tp_sl';

    private const TP_SL_TRIGGER_TYPES = [
        'stop_market',
        'stop_limit',
        'take_profit_market',
        'take_profit_limit',
    ];

    public function closePosition(): ?bool
    {
        return match ($this) {
            self::ALL => null,
            self::CONDITION => false,
            self::TP_SL => true,
        };
    }

    /** @return string[] */
    public function triggerTypes(): array
    {
        return $this === self::TP_SL ? self::TP_SL_TRIGGER_TYPES : [];
    }
}
