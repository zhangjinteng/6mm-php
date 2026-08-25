<?php

declare(strict_types=1);

use Prediction\V1\GetPlatformTemplateResponse;
use Prediction\V1\PlatformTemplateVersion;
use SixMm\Shared\Prediction\PredictionPlatformTemplateService;

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
    static fn (): array => [
        (new GetPlatformTemplateResponse())->setCurrent(
            (new PlatformTemplateVersion())
                ->setVersionId('version-1')
                ->setVersion(1)
        ),
        (object) ['code' => 0, 'details' => ''],
    ],
    1000000,
    '127.0.0.1:18081',
    'test-token',
    false
);

$result = $service->getTemplate('operator-1');
if (($result['current']['version'] ?? null) !== 1) {
    throw new RuntimeException('The shared prediction service did not delegate to 6mm-prediction.');
}

fwrite(STDOUT, "6mm-php prediction integration: passed.\n");
