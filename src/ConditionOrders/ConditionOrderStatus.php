<?php

declare(strict_types=1);

namespace SixMm\Shared\ConditionOrders;

final class ConditionOrderStatus
{
    public const INACTIVE = -1;
    public const PENDING = 0;
    public const TRIGGERING = 1;
    public const TRIGGERED = 2;
    public const CANCELED = 3;
    public const REJECTED = 4;
    public const FIRED = 5;

    /** @return int[] */
    public static function current(): array
    {
        return [self::INACTIVE, self::PENDING, self::TRIGGERING];
    }

    /** @return int[] */
    public static function history(): array
    {
        return [self::TRIGGERED, self::CANCELED, self::REJECTED, self::FIRED];
    }

    /** @return int[] */
    public static function all(): array
    {
        return [...self::current(), ...self::history()];
    }
}
