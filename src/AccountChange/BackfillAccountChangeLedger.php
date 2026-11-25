<?php

declare(strict_types=1);

namespace SixMm\Shared\AccountChange;

use Brick\Math\BigDecimal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SixMm\Shared\UserActions\UserAccountChangeOrder;
use RuntimeException;
use Throwable;

class BackfillAccountChangeLedger implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 6;
    public $timeout = 45;
    public int $orderId;
    public ?string $generation = null;

    public function __construct(int $orderId, ?string $generation = null)
    {
        $this->orderId = $orderId;
        $this->generation = $generation;
        $this->onConnection('redis');
        $this->onQueue(config('account_change_ledger.queue', 'account-change-ledger'));
        $this->delay(max(1, (int) config('account_change_ledger.delay_seconds', 3)));
    }

    public function backoff(): array { return [10, 30, 60, 120, 300]; }

    public function handle(KafkaAccountChangeLedgerReader $reader): void
    {
        $snapshot = UserAccountChangeOrder::query()->find($this->orderId);
        if (!LedgerBackfillDispatcher::eligible($snapshot) || $this->superseded($snapshot)
            || ($snapshot->ledger_id !== null && $snapshot->ledger_id !== '')) return;
        $ledgerId = $reader->findLedgerId($snapshot);
        if ($ledgerId === null || $ledgerId === '') throw new RuntimeException('未找到本次账变的 Kafka 流水，等待队列重试');
        DB::transaction(function () use ($snapshot, $ledgerId): void {
            $order = UserAccountChangeOrder::query()->lockForUpdate()->find($this->orderId);
            if (!LedgerBackfillDispatcher::eligible($order) || $this->superseded($order)) return;
            if ($order->order_no !== $snapshot->order_no || (string) $order->user_id !== (string) $snapshot->user_id
                || $order->asset_type !== $snapshot->asset_type || !BigDecimal::of($order->amount)->isEqualTo($snapshot->amount)) {
                throw new RuntimeException('账变订单匹配字段发生变化，拒绝写入 ledger_id');
            }
            if ($order->ledger_id === $ledgerId) return;
            if ($order->ledger_id !== null && $order->ledger_id !== '') throw new RuntimeException('账变订单已有其他 ledger_id，关联冲突');
            $detail = LedgerBackfillDispatcher::detail($order);
            $detail['ledger_sync'] = array_merge($detail['ledger_sync'] ?? [], ['state' => 'linked', 'linked_at' => now('UTC')->toIso8601String()]);
            DB::table('user_account_change_order')->where('id', $order->id)->update([
                'ledger_id' => $ledgerId, 'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function failed(Throwable $error): void
    {
        DB::transaction(function (): void {
            $order = UserAccountChangeOrder::query()->lockForUpdate()->find($this->orderId);
            if (!LedgerBackfillDispatcher::eligible($order) || $this->superseded($order)
                || ($order->ledger_id !== null && $order->ledger_id !== '')) return;
            $detail = LedgerBackfillDispatcher::detail($order);
            $detail['ledger_sync'] = array_merge($detail['ledger_sync'] ?? [], ['state' => 'failed', 'failed_at' => now('UTC')->toIso8601String()]);
            DB::table('user_account_change_order')->where('id', $order->id)->update(['detail' => json_encode($detail, JSON_UNESCAPED_UNICODE)]);
        });
        Log::channel('daily')->critical('账变流水关联补齐失败，资金结果保持不变', ['order_id' => $this->orderId, 'error' => $error->getMessage()]);
    }

    private function superseded(object $order): bool
    {
        $generation = LedgerBackfillDispatcher::detail($order)['ledger_sync']['generation'] ?? null;
        return $generation !== $this->generation;
    }
}
