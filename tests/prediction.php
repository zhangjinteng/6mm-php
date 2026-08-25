<?php

declare(strict_types=1);

use Prediction\V1\GetPlatformTemplateResponse;
use Prediction\V1\OperationReceipt;
use Prediction\V1\PlatformTemplateVersion;
use Prediction\V1\SavePlatformSymbolConfigResponse;
use SixMm\Shared\Prediction\PredictionGameType;
use SixMm\Shared\Prediction\PredictionPlatformTemplateService;
use SixMm\Shared\Prediction\PredictionRuleStatus;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists(GetPlatformTemplateResponse::class)) {
    $workspaceAutoload = dirname(__DIR__, 2) . '/6mm-prediction/vendor/autoload.php';
    if (is_file($workspaceAutoload)) {
        require $workspaceAutoload;
    }
}

if (!class_exists(GetPlatformTemplateResponse::class)) {
    throw new RuntimeException('zhangjinteng/6mm-prediction is not installed.');
}

$service = new PredictionPlatformTemplateService(
    static function (string $method, object $request): array {
        if ($method === 'GetPlatformTemplate') {
            if ($request->getIncludeDraft()) {
                throw new RuntimeException('Filtered template queries must not request legacy drafts by default.');
            }

            return [
                (new GetPlatformTemplateResponse())->setCurrent(
                    (new PlatformTemplateVersion())
                        ->setVersionId('version-1')
                        ->setVersion(1)
                ),
                (object) ['code' => 0, 'details' => ''],
            ];
        }

        if ($method === 'SavePlatformSymbolConfig') {
            return [
                (new SavePlatformSymbolConfigResponse())
                    ->setVersion((new PlatformTemplateVersion())
                        ->setVersionId('version-2')
                        ->setVersion(2))
                    ->setReceipt((new OperationReceipt())->setOperationId('operation-2')),
                (object) ['code' => 0, 'details' => ''],
            ];
        }

        throw new RuntimeException("Unexpected prediction RPC: {$method}");
    },
    1000000,
    '127.0.0.1:18081',
    'test-token',
    false
);

$result = $service->getFilteredTemplate(
    'operator-1',
    ['BTCUSDT'],
    PredictionGameType::UP_DOWN,
    PredictionRuleStatus::ALL
);
if (($result['current']['version'] ?? null) !== 1) {
    throw new RuntimeException('The shared prediction service did not delegate to 6mm-prediction.');
}
if (($result['current']['rules'][0]['configured'] ?? true) !== false) {
    throw new RuntimeException('The shared prediction service did not complete missing symbols.');
}

$saved = $service->saveSymbolConfig('operator-1', [
    'client_request_id' => 'save-symbol-1',
    'reason' => 'integration test',
    'based_on_version' => 1,
    'game_type' => 'UP_DOWN',
    'symbol' => 'BTCUSDT',
    'platform_enabled' => false,
    'rules' => $result['current']['rules'],
]);
if (($saved['version']['version'] ?? null) !== 2) {
    throw new RuntimeException('The shared prediction service did not delegate direct symbol saves.');
}

fwrite(STDOUT, "6mm-php prediction integration: passed.\n");
