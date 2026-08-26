<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

use RuntimeException;
use SixMm\Prediction\Exceptions\PredictionRpcException;

final class PredictionServiceException extends RuntimeException
{
    public const INVALID_ARGUMENT = 'PREDICTION_SERVICE_INVALID_ARGUMENT';
    public const TIMEOUT = 'PREDICTION_SERVICE_TIMEOUT';
    public const NOT_FOUND = 'PREDICTION_SERVICE_NOT_FOUND';
    public const AUTH_ERROR = 'PREDICTION_SERVICE_AUTH_ERROR';
    public const STATE_CHANGED = 'PREDICTION_SERVICE_STATE_CHANGED';
    public const VERSION_CONFLICT = 'PREDICTION_SERVICE_VERSION_CONFLICT';
    public const UNAVAILABLE = 'PREDICTION_SERVICE_UNAVAILABLE';
    public const CALL_FAILED = 'PREDICTION_SERVICE_CALL_FAILED';

    private function __construct(
        string $userMessage,
        private readonly string $errorCode,
        private readonly string $rpcMethod,
        private readonly int $grpcStatusCode,
        private readonly string $rawDetails,
        PredictionRpcException $previous
    ) {
        parent::__construct($userMessage, $grpcStatusCode, $previous);
    }

    public static function fromRpcException(PredictionRpcException $exception): self
    {
        [$errorCode, $userMessage] = match ($exception->statusCode()) {
            3 => [self::INVALID_ARGUMENT, '玩法配置参数不符合服务约束，请检查后重试'],
            4 => [self::TIMEOUT, '玩法配置服务响应超时'],
            5 => [self::NOT_FOUND, '未找到对应的玩法配置'],
            7, 16 => [self::AUTH_ERROR, '玩法配置服务鉴权异常'],
            9 => [self::STATE_CHANGED, '玩法配置状态已变化，请刷新后重试'],
            10 => [self::VERSION_CONFLICT, '玩法配置版本冲突，请刷新后重试'],
            14 => [self::UNAVAILABLE, '玩法配置服务暂时不可用'],
            default => [self::CALL_FAILED, '预测配置服务调用失败，请稍后重试'],
        };

        return new self(
            $userMessage,
            $errorCode,
            $exception->rpcMethod(),
            $exception->statusCode(),
            $exception->statusDetails(),
            $exception
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function rpcMethod(): string
    {
        return $this->rpcMethod;
    }

    public function grpcStatusCode(): int
    {
        return $this->grpcStatusCode;
    }

    public function rawDetails(): string
    {
        return $this->rawDetails;
    }

    /** @return array<string, int|string> */
    public function logContext(): array
    {
        return [
            'prediction_error_code' => $this->errorCode,
            'rpc_method' => $this->rpcMethod,
            'grpc_status_code' => $this->grpcStatusCode,
            'grpc_status_details' => $this->rawDetails,
        ];
    }
}
