<?php

declare(strict_types=1);

namespace SixMm\Shared\RpcErrors;

use DomainException;
use Throwable;

final class TkRpcException extends DomainException
{
    private function __construct(string $message, public readonly ?int $rejectCode,
        public readonly ?string $grpcStatus, public readonly string $rpcMethod,
        public readonly string $rawMessage = '', ?Throwable $previous = null)
    {
        parent::__construct($message, $rejectCode ?? 0, $previous);
    }

    public static function business(int $code, string $method = '', array $request = [], string $rawMessage = ''): self
    {
        return new self(TkErrorMessages::business($code, $method, $request), $code, null, $method, $rawMessage);
    }

    public static function transport(string|int $status, string $method = '', ?Throwable $previous = null): self
    {
        return new self(TkErrorMessages::transport($status), null, TkErrorMessages::status($status), $method,
            $previous?->getMessage() ?? '', $previous);
    }

    public static function find(Throwable $error): ?self
    {
        do {
            if ($error instanceof self) return $error;
            $error = $error->getPrevious();
        } while ($error !== null);
        return null;
    }

    /** Preserve SDK retry policy and response semantics; translate only transport exceptions. */
    public static function call(string $method, callable $call): mixed
    {
        try { return $call(); }
        catch (Throwable $error) {
            if (is_a($error, 'TradeKernel\Sdk\RpcException')) {
                // Older SDKs collapse several physical statuses into Unknown.
                $wireStatus = \SixMm\Shared\RpcLogging\TkSdkCall::current()?->grpcStatus();
                throw self::transport($wireStatus !== null && $wireStatus !== 0 ? $wireStatus : $error->status(), $method, $error);
            }
            throw $error;
        }
    }
}
