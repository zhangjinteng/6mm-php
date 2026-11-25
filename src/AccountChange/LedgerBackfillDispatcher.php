<?php

declare(strict_types=1);

namespace SixMm\Shared\AccountChange;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SixMm\Shared\UserActions\UserAccountChangeOrder;
use Throwable;

/** Only links completed adjustments. This class must never call a money-changing RPC. */
final class LedgerBackfillDispatcher
{
    public static function eligible(?object $order): bool
    {
        return $order !== null && empty($order->deleted_at)
            && (int) $order->status === UserAccountChangeOrder::STATUS_SUCCESS
            && in_array($order->order_type, [UserAccountChangeOrder::TYPE_ADMIN_RECHARGE, UserAccountChangeOrder::TYPE_ADMIN_DEDUCT], true);
    }

    public static function detail(object $order): array
    {
        $detail = json_decode((string) $order->detail, true);
        return is_array($detail) ? $detail : [];
    }

    public static function due(object $order, bool $retryFailed = false): bool
    {
        if (!self::eligible($order) || ($order->ledger_id !== null && $order->ledger_id !== '')) return false;
        $sync = self::detail($order)['ledger_sync'] ?? [];
        if (($sync['state'] ?? '') === 'failed') return $retryFailed;
        if (empty($sync['queued_at'])) return true;
        return CarbonImmutable::parse($sync['queued_at'], 'UTC')->addSeconds(
            max(900, (int) config('account_change_ledger.stale_seconds', 900))
        )->isPast();
    }

    public function dispatch(int $orderId, bool $retryFailed = false, string $jobClass = BackfillAccountChangeLedger::class): bool
    {
        $generation = null;
        try {
            $generation = DB::transaction(function () use ($orderId, $retryFailed): ?string {
                $order = UserAccountChangeOrder::query()->lockForUpdate()->find($orderId);
                if (!$order || !self::due($order, $retryFailed)) return null;
                $detail = self::detail($order);
                $previous = $detail['ledger_sync'] ?? [];
                $rounds = $retryFailed ? 0 : (int) ($previous['dispatches'] ?? 0);
                if ($rounds >= 3) {
                    $detail['ledger_sync'] = array_merge($previous, ['state' => 'failed', 'failed_at' => now('UTC')->toIso8601String()]);
                    DB::table('user_account_change_order')->where('id', $orderId)->update(['detail' => json_encode($detail, JSON_UNESCAPED_UNICODE)]);
                    Log::channel('daily')->critical('账变流水任务多次未完成，请检查队列消费者', ['order_id' => $orderId]);
                    return null;
                }
                $generation = (string) \Illuminate\Support\Str::uuid();
                $detail['ledger_sync'] = ['state' => 'pending', 'generation' => $generation,
                    'queued_at' => now('UTC')->toIso8601String(), 'dispatches' => $rounds + 1];
                DB::table('user_account_change_order')->where('id', $orderId)->update(['detail' => json_encode($detail, JSON_UNESCAPED_UNICODE)]);
                return $generation;
            });
            if ($generation === null) return false;
            Bus::dispatch((new $jobClass($orderId, $generation))->afterCommit());
            return true;
        } catch (Throwable $error) {
            // A successful financial command stays successful even if the queue is unavailable.
            // The persisted dispatch timestamp allows the platform sweep to recover missed delivery.
            Log::channel('daily')->critical('后台账变已成功，但流水补齐任务投递失败', ['order_id' => $orderId, 'error' => $error->getMessage()]);
            return false;
        }
    }
}
