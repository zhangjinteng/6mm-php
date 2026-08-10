<?php

declare(strict_types=1);

namespace SixMm\Shared\UserActions;

use SixMm\Shared\Contracts\UserDataScope;
use SixMm\Shared\Contracts\UserTradingActionGateway;
use SixMm\Shared\UserDetails\UserDetailQueryService;

final class UserTradingActionService
{
    public function __construct(
        private UserDetailQueryService $userDetails,
        private UserTradingActionGateway $gateway
    ) {
    }

    public function cancelAllOrders(
        int|string $publicUserId,
        UserDataScope $scope
    ): bool {
        return $this->execute($publicUserId, $scope, 'cancelAllOrders');
    }

    public function closeAllPositions(
        int|string $publicUserId,
        UserDataScope $scope
    ): bool {
        return $this->execute($publicUserId, $scope, 'closeAllPositions');
    }

    private function execute(
        int|string $publicUserId,
        UserDataScope $scope,
        string $action
    ): bool {
        $user = $this->userDetails->findByPublicUserId($publicUserId, $scope);
        if ($user === null) {
            return false;
        }

        $this->gateway->{$action}($user->platformUserId());

        return true;
    }
}
