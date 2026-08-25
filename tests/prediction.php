<?php

declare(strict_types=1);

use Prediction\V1\DecimalConfigField;
use Prediction\V1\GameType;
use Prediction\V1\GameplayConfig;
use Prediction\V1\GameplayConfigRule;
use Prediction\V1\GetGameplayConfigResponse;
use Prediction\V1\PriceRule;
use SixMm\Shared\Prediction\PredictionPlatformTemplateService;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists(GetGameplayConfigResponse::class)) {
    $workspaceAutoload = dirname(__DIR__, 2) . '/6mm-prediction/vendor/autoload.php';
    if (is_file($workspaceAutoload)) {
        require $workspaceAutoload;
    }
}

if (!class_exists(GetGameplayConfigResponse::class)) {
    throw new RuntimeException('zhangjinteng/6mm-prediction is not installed.');
}

$workspaceClient = dirname(__DIR__, 2) . '/6mm-prediction/src/PlatformTemplates/PlatformTemplateClient.php';
if (is_file($workspaceClient)) {
    require_once $workspaceClient;
}

$rule = (new GameplayConfigRule())
    ->setGameType(GameType::GAME_TYPE_UP_DOWN)
    ->setSymbol('BTCUSDT')
    ->setDurationSeconds(60)
    ->setEnabled(true)
    ->setEnabledByDefault(true)
    ->setMinimumStake((new DecimalConfigField())
        ->setValue('1')
        ->setTemplateValue('1')
        ->setDefaultValue('1')
        ->setMinimumValue('0.1')
        ->setMaximumValue('100')
        ->setMerchantEditable(true))
    ->setMaximumStake((new DecimalConfigField())
        ->setValue('1000')
        ->setTemplateValue('1000')
        ->setDefaultValue('1000')
        ->setMinimumValue('100')
        ->setMaximumValue('100000')
        ->setMerchantEditable(true))
    ->setSettlementDisplaySeconds(10)
    ->setPriceRule((new PriceRule())
        ->setMaxAgeMilliseconds(5000)
        ->setLateArrivalGraceMilliseconds(500));

$service = new PredictionPlatformTemplateService(
    static function (string $method, object $request) use ($rule): array {
        if ($method !== 'GetGameplayConfig') {
            throw new RuntimeException("Unexpected prediction RPC: {$method}");
        }
        if ($request->getGameType() !== GameType::GAME_TYPE_UNSPECIFIED || $request->getSymbol() !== '') {
            throw new RuntimeException('Unfiltered gameplay queries must not set RPC filters.');
        }

        return [
            (new GetGameplayConfigResponse())->setConfiguration(
                (new GameplayConfig())
                    ->setTemplateVersion(1)
                    ->setEffectiveVersion('1:0')
                    ->setRules([$rule])
            ),
            (object) ['code' => 0, 'details' => ''],
        ];
    },
    1000000,
    '127.0.0.1:18081',
    'test-token',
    false
);

$result = $service->getTemplate('operator-1');
if (($result['current']['version'] ?? null) !== 1) {
    throw new RuntimeException('The shared prediction service did not delegate to 6mm-prediction.');
}
if (($result['current']['rules'][0]['symbol'] ?? null) !== 'BTCUSDT') {
    throw new RuntimeException('The shared prediction service did not map gameplay rules.');
}

fwrite(STDOUT, "6mm-php prediction integration: passed.\n");
