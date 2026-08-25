<?php

declare(strict_types=1);

use SixMm\Shared\Prediction\PredictionGameType;
use SixMm\Shared\Prediction\PredictionRuleCatalog;
use SixMm\Shared\Prediction\PredictionRuleStatus;

require dirname(__DIR__) . '/vendor/autoload.php';

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual:   %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
};

$rules = [
    [
        'game_type' => 'UP_DOWN',
        'symbol' => 'BTCUSDT',
        'duration_seconds' => 60,
        'enabled_by_default' => true,
    ],
    [
        'game_type' => 'UP_DOWN',
        'symbol' => 'BTCUSDT',
        'duration_seconds' => 30,
        'enabled_by_default' => true,
    ],
    [
        'game_type' => 'UP_DOWN',
        'symbol' => 'ETHUSDT',
        'duration_seconds' => 30,
        'enabled_by_default' => false,
    ],
    [
        'game_type' => 'UP_DOWN',
        'symbol' => 'ETHUSDT',
        'duration_seconds' => 60,
        'enabled_by_default' => true,
    ],
    [
        'game_type' => 'HIGH_LOW',
        'symbol' => 'BTCUSDT',
        'duration_seconds' => 30,
        'enabled_by_default' => true,
        'fixed_odds' => ['default_value' => '1.9'],
    ],
    [
        'game_type' => 'UP_DOWN',
        'symbol' => 'RPCONLYUSDT',
        'duration_seconds' => 30,
        'enabled_by_default' => true,
    ],
];
$symbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'btcusdt', ''];
$catalog = new PredictionRuleCatalog();

$all = $catalog->mergeAndFilter(
    $rules,
    $symbols,
    PredictionGameType::UP_DOWN,
    PredictionRuleStatus::ALL
);
$assertSame(5, count($all), 'All should return every host symbol and every configured duration.');
$assertSame(
    ['BTCUSDT:30', 'BTCUSDT:60', 'ETHUSDT:30', 'ETHUSDT:60', 'SOLUSDT:30'],
    array_map(static fn (array $rule): string =>
        $rule['symbol'] . ':' . $rule['duration_seconds'], $all),
    'Host symbol order should be stable and durations should be sorted.'
);
$assertSame(true, $all[0]['configured'], 'RPC rules should be marked as configured.');
$assertSame(
    PredictionRuleCatalog::SOURCE_PREDICTION_SERVICE,
    $all[0]['source'],
    'RPC rules should expose their source.'
);
$assertSame(false, $all[4]['configured'], 'Missing RPC symbols should be marked as defaults.');
$assertSame(false, $all[4]['enabled_by_default'], 'Generated defaults must never be enabled implicitly.');
$assertSame(
    PredictionRuleCatalog::SOURCE_SYMBOL_CONFIG_DEFAULT,
    $all[4]['source'],
    'Generated rules should expose their source.'
);
$assertSame(15, $all[4]['bet_open_seconds']['default_value'], 'UP_DOWN defaults should match the agreed template.');

$enabled = $catalog->mergeAndFilter(
    $rules,
    $symbols,
    PredictionGameType::UP_DOWN,
    PredictionRuleStatus::ENABLED
);
$assertSame(2, count($enabled), 'Enabled should retain every duration of fully enabled symbols.');
$assertSame(
    ['BTCUSDT', 'BTCUSDT'],
    array_column($enabled, 'symbol'),
    'Only fully enabled symbols should match the enabled filter.'
);

$disabled = $catalog->mergeAndFilter(
    $rules,
    $symbols,
    PredictionGameType::UP_DOWN,
    PredictionRuleStatus::DISABLED
);
$assertSame(
    ['ETHUSDT', 'ETHUSDT', 'SOLUSDT'],
    array_column($disabled, 'symbol'),
    'A partially enabled RPC symbol and a missing RPC symbol should both be disabled.'
);

$highLow = $catalog->mergeAndFilter(
    $rules,
    $symbols,
    PredictionGameType::HIGH_LOW,
    PredictionRuleStatus::ALL
);
$assertSame(3, count($highLow), 'HIGH_LOW should contain one rule for every host symbol.');
$assertSame(true, $highLow[0]['configured'], 'Existing HIGH_LOW rules should be retained.');
$assertSame(false, $highLow[1]['configured'], 'Missing HIGH_LOW rules should be generated.');
$assertSame(null, $highLow[1]['bet_open_seconds'], 'HIGH_LOW defaults should not include bet-open constraints.');
$assertSame('1.85', $highLow[1]['fixed_odds']['default_value'], 'HIGH_LOW fixed odds should match the agreed template.');

fwrite(STDOUT, "6mm-php prediction rule catalog tests passed.\n");
