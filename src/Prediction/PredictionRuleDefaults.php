<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

final class PredictionRuleDefaults
{
    /** @return array<string, mixed> */
    public function forSymbol(PredictionGameType $gameType, string $symbol): array
    {
        $shared = [
            'game_type' => $gameType->value,
            'symbol' => strtoupper(trim($symbol)),
            'duration_seconds' => 30,
            'enabled_by_default' => false,
            'minimum_stake' => $this->decimalConstraint('1', '0.1', '100'),
            'maximum_stake' => $this->decimalConstraint('3000', '100', '100000'),
            'settlement_display_seconds' => 10,
            'price_rule' => [
                'max_age_milliseconds' => 5000,
                'late_arrival_grace_milliseconds' => 500,
            ],
        ];

        return match ($gameType) {
            PredictionGameType::UP_DOWN => [
                ...$shared,
                'bet_open_seconds' => $this->integerConstraint(15, 5, 20),
                'target_payout_rate' => $this->decimalConstraint('0.9', '0.7', '0.95'),
                'minimum_odds' => $this->decimalConstraint('1.2', '1', '2'),
                'maximum_odds' => $this->decimalConstraint('5', '2', '10'),
                'fixed_odds' => null,
            ],
            PredictionGameType::HIGH_LOW => [
                ...$shared,
                'bet_open_seconds' => null,
                'target_payout_rate' => null,
                'minimum_odds' => null,
                'maximum_odds' => null,
                'fixed_odds' => $this->decimalConstraint('1.85', '1.1', '3'),
            ],
        };
    }

    /** @return array{default_value: string, minimum_value: string, maximum_value: string, merchant_editable: true} */
    private function decimalConstraint(string $default, string $minimum, string $maximum): array
    {
        return [
            'default_value' => $default,
            'minimum_value' => $minimum,
            'maximum_value' => $maximum,
            'merchant_editable' => true,
        ];
    }

    /** @return array{default_value: int, minimum_value: int, maximum_value: int, merchant_editable: true} */
    private function integerConstraint(int $default, int $minimum, int $maximum): array
    {
        return [
            'default_value' => $default,
            'minimum_value' => $minimum,
            'maximum_value' => $maximum,
            'merchant_editable' => true,
        ];
    }
}
