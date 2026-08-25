<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction;

final class PredictionRuleCatalog
{
    public const SOURCE_PREDICTION_SERVICE = 'prediction_service';
    public const SOURCE_SYMBOL_CONFIG_DEFAULT = 'symbol_config_default';

    private readonly PredictionRuleDefaults $defaults;

    public function __construct(?PredictionRuleDefaults $defaults = null)
    {
        $this->defaults = $defaults ?? new PredictionRuleDefaults();
    }

    /**
     * @param array<int, array<string, mixed>> $rpcRules
     * @param iterable<mixed, string> $symbols
     * @return list<array<string, mixed>>
     */
    public function mergeAndFilter(
        array $rpcRules,
        iterable $symbols,
        PredictionGameType $gameType,
        PredictionRuleStatus $status = PredictionRuleStatus::ALL
    ): array {
        $symbolList = $this->normalizeSymbols($symbols);
        $allowedSymbols = array_fill_keys($symbolList, true);
        $rulesBySymbol = [];

        foreach ($rpcRules as $rule) {
            if (($rule['game_type'] ?? null) !== $gameType->value) {
                continue;
            }

            $symbol = strtoupper(trim((string) ($rule['symbol'] ?? '')));
            if ($symbol === '' || !isset($allowedSymbols[$symbol])) {
                continue;
            }

            $rule['game_type'] = $gameType->value;
            $rule['symbol'] = $symbol;
            $rule['configured'] = true;
            $rule['source'] = self::SOURCE_PREDICTION_SERVICE;
            $rulesBySymbol[$symbol][] = $rule;
        }

        $result = [];
        foreach ($symbolList as $symbol) {
            $rules = $rulesBySymbol[$symbol] ?? [$this->defaultRule($gameType, $symbol)];
            usort($rules, static fn (array $left, array $right): int =>
                (int) ($left['duration_seconds'] ?? 0) <=> (int) ($right['duration_seconds'] ?? 0)
            );

            if (!$status->matches($this->isEnabled($rules))) {
                continue;
            }

            array_push($result, ...$rules);
        }

        return $result;
    }

    /** @param iterable<mixed, string> $symbols @return list<string> */
    private function normalizeSymbols(iterable $symbols): array
    {
        $result = [];
        $seen = [];
        foreach ($symbols as $value) {
            $symbol = strtoupper(trim((string) $value));
            if ($symbol === '' || isset($seen[$symbol])) {
                continue;
            }

            $seen[$symbol] = true;
            $result[] = $symbol;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rules */
    private function isEnabled(array $rules): bool
    {
        if ($rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            if (($rule['enabled_by_default'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function defaultRule(PredictionGameType $gameType, string $symbol): array
    {
        return [
            ...$this->defaults->forSymbol($gameType, $symbol),
            'configured' => false,
            'source' => self::SOURCE_SYMBOL_CONFIG_DEFAULT,
        ];
    }
}
