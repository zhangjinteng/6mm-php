<?php

namespace SixMm\Shared\UserActions;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Platform's manual-adjustment workflow. Hosts MUST authorize the internal user before calling. */
final class ManualUserAdjustment
{
    /** Query/reconcile only: NEVER create an intent or execute AccountChange. */
    public function query(array $params, callable $receipt, string $orderClass = UserAccountChangeOrder::class, ?callable $onSuccess = null): array
    {
        $order = $orderClass::withTrashed()->where('out_request_no', (string) $params['out_request_no'])->first();
        $amount = BigDecimal::of($params['balance']);
        if ($params['type'] === 'dec') $amount = $amount->negated();
        if ($order) {
            $detail = json_decode((string) $order->detail, true) ?: [];
            if ((string) $order->user_id !== (string) $params['user_id']
                || $order->order_type !== ($params['type'] === 'dec' ? UserAccountChangeOrder::TYPE_ADMIN_DEDUCT : UserAccountChangeOrder::TYPE_ADMIN_RECHARGE)
                || !BigDecimal::of($order->amount)->isEqualTo($amount)
                || ($detail['scope'] ?? 'platform') !== ($params['scope'] ?? 'platform')
                || (isset($detail['reason']) && $detail['reason'] !== trim($params['reason']))) {
                return self::fail('原请求信息不匹配');
            }
            if ((int) $order->status === UserAccountChangeOrder::STATUS_PENDING) {
                $this->reconcile($order, $receipt, $orderClass);
                $order = $orderClass::withTrashed()->findOrFail($order->getKey());
            }
            $status = match ((int) $order->status) {
                UserAccountChangeOrder::STATUS_SUCCESS => 'success',
                UserAccountChangeOrder::STATUS_FAILED => 'failed',
                default => 'pending',
            };
            if ($status === 'success' && $onSuccess && !$order->trashed()) $onSuccess($order);
            return ['status' => $status];
        }

        // A missing local record is NOT proof that the original command did not execute.
        try {
            $result = $receipt((string) $params['out_request_no']);
            $state = $this->receiptState($result, (string) $params['out_request_no'], (string) $params['user_id'], $amount);
            return ['status' => $state ?? 'unknown'];
        } catch (\Throwable $error) {
            report($error);
            return ['status' => 'unknown'];
        }
    }

    public function adjust(array $params, callable $execute, callable $isUnknown, ?callable $onSuccess = null, ?callable $beforeCreate = null, string $orderClass = UserAccountChangeOrder::class, ?callable $receipt = null): array
    {
        if (DB::connection()->transactionLevel() > 0) throw new \LogicException('AccountChange must execute after the local intent is committed');
        $userId = $params['user_id'];
        $metadata = ['scope' => $params['scope'] ?? 'platform'];
        $confirmed = static function ($order) use ($onSuccess): void {
            if ($onSuccess) $onSuccess($order);
        };
        $amount = BigDecimal::of($params['balance']);
        $type = $params['type']; // inc 或 dec
        $reason = trim((string) ($params['reason'] ?? ''));

        if ($amount->isLessThanOrEqualTo(0)) {
            return self::fail("金额必须大于0");
        }

        if (!in_array($type, ['inc', 'dec'], true) || mb_strlen($reason) > 200) {
            return self::fail('调账类型或操作原因无效');
        }

        if ($type === 'dec') {
            $changeAmount = $amount->negated();
            $orderType = UserAccountChangeOrder::TYPE_ADMIN_DEDUCT;
        } else {
            $changeAmount = $amount;
            $orderType = UserAccountChangeOrder::TYPE_ADMIN_RECHARGE;
        }

        $outRequestNo = (string) $params['out_request_no'];
        $successMessage = $type === 'inc' ? '充值成功' : '扣减成功';

        $existingOrder = $orderClass::query()
            ->where('out_request_no', $outRequestNo)
            ->first();
        if ($existingOrder) {
            $sameRequest = (int) $existingOrder->user_id === (int) $userId
                && $existingOrder->order_type === $orderType
                && BigDecimal::of($existingOrder->amount)->isEqualTo($changeAmount);
            $existingDetail = json_decode((string) $existingOrder->detail, true);
            if (is_array($existingDetail) && array_key_exists('reason', $existingDetail)) {
                $sameRequest = $sameRequest && $existingDetail['reason'] === $reason;
            }
            $sameRequest = $sameRequest && ($existingDetail['scope'] ?? 'platform') === $metadata['scope'];
            if (!$sameRequest) {
                return self::fail('外部请求号已被其他账变使用');
            }

            if ((int) $existingOrder->status === UserAccountChangeOrder::STATUS_PENDING && $receipt) {
                $this->reconcile($existingOrder, $receipt, $orderClass);
                $existingOrder->refresh();
            }
            if ((int) $existingOrder->status === UserAccountChangeOrder::STATUS_SUCCESS) {
                $confirmed($existingOrder);
                return self::ok($successMessage);
            }

            if ((int) $existingOrder->status === UserAccountChangeOrder::STATUS_FAILED) {
                return self::fail('上次调账未成功，请重新提交', [
                    'status' => 'failed',
                    'order_no' => (string) $existingOrder->order_no,
                ]);
            }

            return self::fail('账变请求正在确认中，请勿重复提交', [
                'status' => 'pending',
                'order_no' => (string) $existingOrder->order_no,
            ]);
        }

        // 防抖：缓存锁防止重复提交（原子操作，防并发）
        $cacheKey = "balance_change:out_request_no:{$outRequestNo}";
        if (!Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return self::fail("请勿重复提交");
        }

        $orderNo = (string) Str::uuid();

        try {
            // 先持久化待处理订单，再调用远程服务，避免远程成功后本地订单被事务回滚。
            $order = DB::transaction(function () use ($orderClass, $beforeCreate, $orderNo, $outRequestNo, $userId, $changeAmount, $orderType, $params, $reason, $type, $metadata) {
                if ($beforeCreate) $beforeCreate();
                return $orderClass::query()->create([
                'order_no' => $orderNo,
                'out_request_no' => $outRequestNo,
                'user_id' => $userId,
                'amount' => $changeAmount->toScale(8, RoundingMode::DOWN)->__toString(),
                'status' => UserAccountChangeOrder::STATUS_PENDING,
                'order_type' => $orderType,
                'operator_id' => $params['operator_id'] ?? 0,
                'asset_type' => 'USDT',
                'description' => $reason !== '' ? $reason : ($type === 'inc' ? '后台充值' : '后台扣减'),
                'detail' => json_encode(['state' => 'pending', 'reason' => $reason, ...$metadata], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            Cache::forget($cacheKey);
            throw $e;
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            Log::channel('daily')->error('创建后台账变订单失败', [
                'out_request_no' => $outRequestNo,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return self::fail('创建账变订单失败，请稍后重试', ['status' => 'error']);
        }

        $requestData = [
            'command_id' => $outRequestNo,//幂等键
            'user_id' => $userId,//用户ID
            'amount' => $changeAmount->toScale(8, RoundingMode::DOWN)->__toString(),//有符号金额
            'change_type' => 1,//变动类型：1=admin_adjust
            'transaction_id' => $orderNo,//外部交易ID
            'remark' => $type === 'inc' ? '后台充值' : '后台扣减',//备注
        ];

        try {
            $execute($requestData);
        } catch (\Throwable $e) {
            if ($isUnknown($e)) {
            $order->update([
                'detail' => json_encode([
                    'state' => 'pending',
                    'reason' => $reason, ...$metadata,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            Log::channel('daily')->warning('后台账变结果待确认', [
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'out_request_no' => $outRequestNo,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return self::fail('账变请求已提交，交易结果待确认', [
                'status' => 'pending',
                'order_no' => $orderNo,
            ]);
            }
            $failed = $orderClass::query()
                ->whereKey($order->id)
                ->where('status', UserAccountChangeOrder::STATUS_PENDING)
                ->update([
                    'status' => UserAccountChangeOrder::STATUS_FAILED,
                    'detail' => json_encode([
                        'state' => 'failed',
                        'reason' => $reason, ...$metadata,
                        'error' => $e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

            if ($failed !== 1) {
                $order->refresh();
                if ((int) $order->status === UserAccountChangeOrder::STATUS_SUCCESS) {
                    $confirmed($order);
                    return self::ok($successMessage);
                }

                return self::fail('账变请求正在确认中，请勿重复提交', [
                    'status' => 'pending',
                    'order_no' => $orderNo,
                ]);
            }

            return self::fail("操作失败：" . $e->getMessage(), [
                'status' => 'failed',
                'order_no' => $orderNo,
            ]);
        }

        $updated = $orderClass::query()
            ->whereKey($order->id)
            ->where('status', UserAccountChangeOrder::STATUS_PENDING)
            ->update([
                'status' => UserAccountChangeOrder::STATUS_SUCCESS,
                'detail' => json_encode([
                    'state' => 'success',
                    'reason' => $reason, ...$metadata,
                    'confirmed_by' => 'grpc_response',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        if ($updated !== 1) {
            $order->refresh();
            if ((int) $order->status === UserAccountChangeOrder::STATUS_SUCCESS) {
                $confirmed($order);
                return self::ok($successMessage);
            }

            Log::channel('daily')->error('后台账变成功状态回写失败', [
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'out_request_no' => $outRequestNo,
                'user_id' => $userId,
            ]);

            return self::fail('账变已执行，订单状态待确认', [
                'status' => 'pending',
                'order_no' => $orderNo,
            ]);
        }

        $confirmed($order);
        return self::ok($successMessage);
    }


    private static function ok(string $message): array
    {
        return ['status' => 'success', 'message' => $message];
    }

    private static function fail(string $message, array $data = []): array
    {
        return $data + ['status' => 'rejected', 'message' => $message];
    }

    private function reconcile(object $order, callable $receipt, string $orderClass): void
    {
        try {
            $result = $receipt((string) $order->out_request_no);
            $state = $this->receiptState($result, (string) $order->out_request_no, (string) $order->user_id, BigDecimal::of($order->amount));
            if ($state === null) return;
            $success = $state === 'success';
            $detail = json_decode((string) $order->detail, true) ?: [];
            $orderClass::withTrashed()->whereKey($order->id)->where('status', 0)->update([
                'status' => $success ? 1 : 2,
                'detail' => json_encode(array_merge($detail, ['state' => $success ? 'success' : 'failed', 'confirmed_by' => 'rpc_receipt']), JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $error) {
            report($error);
        }
    }

    private function receiptState(array $result, string $command, string $userId, BigDecimal $amount): ?string
    {
        if ((string) ($result['command_id'] ?? '') !== $command
            || (string) ($result['user_id'] ?? '') !== $userId) return null;
        if ((int) ($result['status'] ?? 0) === 2 && (int) ($result['reject_code'] ?? -1) === 0
            && isset($result['amount']) && BigDecimal::of($result['amount'])->isEqualTo($amount)) return 'success';
        if ((int) ($result['status'] ?? 0) === 3 && (int) ($result['reject_code'] ?? 0) !== 0) return 'failed';
        return null;
    }
}
