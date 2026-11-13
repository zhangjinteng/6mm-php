<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class WriteMethodPolicy
{
    private const WRITES = [
        'prediction' => ['InitializeMerchantConfigs','SaveSymbolConfiguration','SaveSymbolConfig','SaveSiteConfig','SaveOwnerConfig','SaveStrategy','UpsertRobotBinding','DeleteRobotBinding','PrepareSiteBindingChange','CommitSiteBindingChange','CancelSiteBindingChange','RemovePlatformRobot','RegisterPlatformRobot','SetPlatformRobotEnabled','CreatePlatformRobotOperation','CreatePlatformRobotUser','SavePlatformRobotAutoDepositPolicy'],
        'condition' => ['CancelConditionOrder'],
        'tronpay' => ['CreateDepositQR','Transfer'],
        'hedging' => ['UpsertExposure','UpsertPosition','RunHedge','ExitHedge','EnqueueHedgeRun'],
        'funding' => ['CreateFundingAsset','ChangeFundingAssetStatus','CreditDeposit','CreditFunding','DebitFunding','BatchApplyFundingMutations','CreateFundingHold','CaptureFundingHold','ReleaseFundingHold','CreateWithdrawalHold','SettleWithdrawalHold','ReleaseWithdrawalHold','CreateFundingAdjustment','ReviewFundingAdjustment','CreateAccountTransfer','CreditFundingTransfer','ResolveFundingTransfer'],
    ];

    public static function isWrite(string $service, string $method): bool
    {
        $method = basename($method);
        if ($service === 'tk') {
            // Some queries are deliberately routed to the write node. Route role is not a mutation policy.
            $policy = (new \TradeKernel\Sdk\RpcMethodClassifier())->classify($method);
            return $policy['retry_class'] !== \TradeKernel\Sdk\RetryClass::QUERY_ONLY
                && !in_array($method, ['ValidatePlaceOrder','GetWALStream'], true);
        }
        if (in_array($method, self::WRITES[$service] ?? [], true)) return true;
        if (preg_match('/^(Get|List|Query|Ping|Check|Validate)/D', $method)) return false;
        throw new \InvalidArgumentException('Missing explicit RPC ticket method policy: '.$service.'/'.$method);
    }
}
