<?php
declare(strict_types=1);

namespace SixMm\Shared\RpcLogging;

use Fiber;
use Throwable;
use WeakMap;

/** Correlates the SDK boundary with physical attempts, without owning any retry or validation policy. */
final class TkSdkCall
{
    private static ?self $main = null;
    private static ?WeakMap $fibers = null;
    private string $id;
    private int $started;
    private array $attempt = [];
    private ?int $status = null;
    private string $details = '';
    private WeakMap $errors;

    private function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
        $this->started = hrtime(true);
        $this->errors = new WeakMap();
    }

    public static function run(string $identity, string $method, mixed $request, callable $call,
        array $context = [], ?callable $writer = null): mixed
    {
        try { $scope = new self(); }
        catch (Throwable) { return $call(); }
        $previous = self::current();
        self::setCurrent($scope);
        try {
            return $call();
        } catch (Throwable $error) {
            try {
                if (!$scope->alreadyLogged($error)) {
                    $stage = !$scope->attempt ? 'sdk_before_send'
                        : ($scope->status === 0 ? 'sdk_after_response' : 'sdk_between_attempts');
                    $logger = new TkRpcLogger($identity, $method, (string) ($scope->attempt['endpoint'] ?? ''), [
                        'sdk_call_id' => $scope->id,
                        'call_id' => $scope->attempt['call_id'] ?? $scope->id,
                        'sdk_duration_ms' => round((hrtime(true) - $scope->started) / 1000000, 3),
                        'sdk_context' => $context,
                    ], $writer);
                    $logger->sdkException($request, $error, $stage);
                }
            } catch (Throwable) {}
            $previous?->exception($error);
            throw $error;
        } finally {
            self::setCurrent($previous);
        }
    }

    public static function current(): ?self
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? self::$main : (self::$fibers[$fiber] ?? null);
    }

    private static function setCurrent(?self $scope): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) self::$main = $scope;
        elseif ($scope === null) unset(self::$fibers[$fiber]);
        else {
            self::$fibers ??= new WeakMap();
            self::$fibers[$fiber] = $scope;
        }
    }

    public function id(): string { return $this->id; }
    public function grpcStatus(): ?int { return $this->status; }

    public function beginAttempt(array $context): void
    {
        $this->attempt = $context;
        $this->status = null;
        $this->details = '';
    }

    public function response(?int $status, string $details): void
    {
        $this->status = $status;
        $this->details = $details;
    }

    public function exception(Throwable $error): void { $this->errors[$error] = true; }

    private function alreadyLogged(Throwable $error): bool
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if (isset($this->errors[$cause])) return true;
            if ($this->matchesTransportStatus($cause)) return true;
        }
        return false;
    }

    private function matchesTransportStatus(Throwable $error): bool
    {
        if (!$this->attempt || $this->status === null || $this->status === 0
            || !is_a($error, 'TradeKernel\\Sdk\\RpcException')) return false;
        $statuses = match ($this->status) {
            1 => ['Cancelled'], 3 => ['InvalidArgument'], 4 => ['DeadlineExceeded'],
            6 => ['AlreadyExists'], 9 => ['FailedPrecondition'], 12 => ['Unknown', 'Unimplemented'],
            13 => ['Internal'], 14 => ['Unavailable'], default => ['Unknown'],
        };
        $status = $error->status();
        return in_array($status, $statuses, true)
            && $error->getMessage() === ($this->details !== '' ? $this->details : $status);
    }
}
