<?php

declare(strict_types=1);

namespace SixMm\Shared\Contracts;

interface UserTradingActionGateway
{
    public function cancelAllOrders(int $platformUserId): void;

    public function closeAllPositions(int $platformUserId): void;
}
