<?php

declare(strict_types=1);

use SixMm\Shared\Prediction\PredictionGameType;
use SixMm\Shared\Prediction\PredictionPlatformTemplateService;
use SixMm\Shared\Prediction\PredictionServiceException;

require dirname(__DIR__) . '/vendor/autoload.php';

$cases = [
    14 => [PredictionServiceException::UNAVAILABLE, '玩法配置服务暂时不可用'],
    4 => [PredictionServiceException::TIMEOUT, '玩法配置服务响应超时'],
    7 => [PredictionServiceException::AUTH_ERROR, '玩法配置服务鉴权异常'],
    16 => [PredictionServiceException::AUTH_ERROR, '玩法配置服务鉴权异常'],
];
$rawDetails = 'ipv4:192.168.10.101:18081 connection refused';

foreach ($cases as $statusCode => [$expectedErrorCode, $expectedMessage]) {
    $service = new PredictionPlatformTemplateService(
        static fn (): array => [null, (object) [
            'code' => $statusCode,
            'details' => $rawDetails,
        ]],
        1000000,
        '127.0.0.1:18081',
        'test-token',
        false
    );

    try {
        $service->getTemplateByGameType('operator-1', PredictionGameType::UP_DOWN);
        throw new RuntimeException("Status {$statusCode} did not throw a shared prediction exception.");
    } catch (PredictionServiceException $exception) {
        if ($exception->errorCode() !== $expectedErrorCode) {
            throw new RuntimeException("Status {$statusCode} returned an unexpected error code.");
        }
        if ($exception->getMessage() !== $expectedMessage) {
            throw new RuntimeException("Status {$statusCode} returned an unexpected user message.");
        }
        if (str_contains($exception->getMessage(), '192.168.10.101')) {
            throw new RuntimeException("Status {$statusCode} leaked raw gRPC details.");
        }
        if ($exception->rawDetails() !== $rawDetails) {
            throw new RuntimeException("Status {$statusCode} did not preserve diagnostic details.");
        }
        if ($exception->rpcMethod() !== 'GetGameplayConfig') {
            throw new RuntimeException("Status {$statusCode} did not preserve the RPC method.");
        }
        $logContext = $exception->logContext();
        if (($logContext['prediction_error_code'] ?? null) !== $expectedErrorCode
            || ($logContext['grpc_status_code'] ?? null) !== $statusCode
            || ($logContext['grpc_status_details'] ?? null) !== $rawDetails
        ) {
            throw new RuntimeException("Status {$statusCode} returned incomplete log context.");
        }
    }
}

fwrite(STDOUT, "6mm-php prediction error mapping: passed.\n");
