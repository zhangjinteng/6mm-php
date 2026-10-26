<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction\Configuration;

use Illuminate\Validation\Rule;

final class PlatformConfigRules
{
    private const DECIMAL_PATTERN = '/^(?:0|[1-9]\d*)(?:\.\d+)?$/';
    private const INTEGER_PATTERN = '/^\d+$/';
    public static function rules(callable $unsigned): array
    {
        $rules = [
            'client_request_id' => ['required', 'string', 'max:128'],
            'reason' => ['required', 'string', 'max:500'],
            'based_on_template_version' => array_merge(['required'], $unsigned()),
            'based_on_agent_version' => ['prohibited'],
            'agent_id' => ['prohibited'],
            'agent_override' => ['prohibited'],
            'game_type' => ['required', Rule::in(['UP_DOWN', 'HIGH_LOW'])],
            'symbol' => ['required', 'regex:/^[A-Z0-9]{2,32}$/'],
            'platform_template' => ['required', 'array'],
            'platform_template.enabled' => ['required', 'boolean'],
            'platform_template.rules' => ['required', 'array', 'min:1'],
            'platform_template.rules.*' => ['required', 'array'],
            'platform_template.rules.*.game_type' => ['prohibited'],
            'platform_template.rules.*.symbol' => ['prohibited'],
            'platform_template.rules.*.duration_seconds' => array_merge(['required'], $unsigned('9223372036854775807', false)),
            'platform_template.rules.*.enabled_by_default' => ['required', 'boolean'],
            'platform_template.rules.*.bet_open_seconds' => ['required_if:game_type,UP_DOWN', 'array'],
            'platform_template.rules.*.target_payout_rate' => ['required_if:game_type,UP_DOWN', 'array'],
            'platform_template.rules.*.minimum_odds' => ['required_if:game_type,UP_DOWN', 'array'],
            'platform_template.rules.*.maximum_odds' => ['required_if:game_type,UP_DOWN', 'array'],
            'platform_template.rules.*.fixed_odds' => ['required_if:game_type,HIGH_LOW', 'array'],
            'platform_template.rules.*.minimum_stake' => ['required_if:game_type,HIGH_LOW', 'prohibited_if:game_type,UP_DOWN', 'array'],
            'platform_template.rules.*.maximum_stake' => ['required_if:game_type,HIGH_LOW', 'prohibited_if:game_type,UP_DOWN', 'array'],
            'platform_template.rules.*.settlement_display_seconds' => array_merge(['required'], $unsigned()),
            'platform_template.rules.*.price_rule' => ['required', 'array'],
            'platform_template.rules.*.price_rule.price_decimals' => ['sometimes', 'required', 'integer', 'min:0', 'max:8', static function ($attribute, $value, $fail): void {
                if (!is_int($value)) { $fail('价格小数位必须是0～8的整数'); }
            }],
            'platform_template.rules.*.price_rule.max_age_milliseconds' => array_merge(['required'], $unsigned()),
            'platform_template.rules.*.price_rule.late_arrival_grace_milliseconds' => array_merge(['required'], $unsigned()),
            'platform_template.rules.*.market_id' => ['nullable', 'string', 'max:128'],
            'platform_template.rules.*.stake_tiers' => ['required_if:game_type,UP_DOWN', 'array'],
            'platform_template.rules.*.stake_tiers.*.stake_tier_id' => ['nullable', 'string', 'max:128'],
            'platform_template.rules.*.stake_tiers.*.enabled_by_default' => ['required', 'boolean'],
            'platform_template.rules.*.stake_tiers.*.display_order' => ['required', 'integer', 'min:0'],
            'platform_template.rules.*.stake_tiers.*.minimum_stake' => ['required', 'array'],
            'platform_template.rules.*.stake_tiers.*.maximum_stake' => ['required', 'array'],
            'platform_template.rules.*.stake_tiers.*.retired' => ['required', 'boolean'],
        ];
        foreach ([
            'target_payout_rate',
            'minimum_odds',
            'maximum_odds',
            'fixed_odds',
            'minimum_stake',
            'maximum_stake',
            'stake_tiers.*.minimum_stake',
            'stake_tiers.*.maximum_stake',
        ] as $field) {
            foreach (['default_value', 'minimum_value', 'maximum_value'] as $valueField) {
                $rules["platform_template.rules.*.{$field}.{$valueField}"] = ['required_with:platform_template.rules.*.' . $field, 'string', 'max:128', 'regex:' . self::DECIMAL_PATTERN];
            }
            $rules["platform_template.rules.*.{$field}.agent_editable"] = ['required_with:platform_template.rules.*.' . $field, 'boolean'];
        }
        foreach (['default_value', 'minimum_value', 'maximum_value'] as $valueField) {
            $rules["platform_template.rules.*.bet_open_seconds.{$valueField}"] = ['required_if:game_type,UP_DOWN', 'regex:' . self::INTEGER_PATTERN];
        }
        $rules['platform_template.rules.*.bet_open_seconds.agent_editable'] = ['required_if:game_type,UP_DOWN', 'boolean'];
        return $rules;
    }

    public static function checkValues(array $payload, \Illuminate\Validation\Validator $v): void
    {
        $decimals = array_map(static fn (array $row) => $row['price_rule']['price_decimals'] ?? null, $payload['platform_template']['rules']);
        if (count(array_unique($decimals, SORT_REGULAR)) > 1 || (in_array(null, $decimals, true) && count(array_filter($decimals, static fn ($d) => $d !== null)) > 0)) {
            $v->errors()->add('platform_template', '同一币种各周期的价格小数位必须一致');
        }
        $check = function (array $constraint) use ($v): void {
            $min = \Brick\Math\BigDecimal::of($constraint['minimum_value']);
            $def = \Brick\Math\BigDecimal::of($constraint['default_value']);
            $max = \Brick\Math\BigDecimal::of($constraint['maximum_value']);
            if ($min->isGreaterThan($def) || $def->isGreaterThan($max)) {
                $v->errors()->add('platform_template', '配置范围必须有序');
            }
        };
        $bounds = function (array $minimum, array $maximum) use ($v): void {
            foreach ([$minimum,$maximum] as $constraint) {
                foreach (['default_value','minimum_value','maximum_value'] as $field) {
                    if (preg_match('/^(0|[1-9][0-9]{0,17})(\.[0-9]{1,18})?$/D',$constraint[$field])!==1) {
                        $v->errors()->add('platform_template', '单笔限额整数和小数最多18位');
                    }
                }
            }
            $min=\Brick\Math\BigDecimal::of($minimum['default_value']);
            $max=\Brick\Math\BigDecimal::of($maximum['default_value']);
            if ($min->isLessThanOrEqualTo(0) || $max->isLessThan($min)) {
                $v->errors()->add('platform_template', '单笔最小额必须为正数且不能大于最大额');
            }
        };
        foreach ($payload['platform_template']['rules'] as $rule) {
            foreach ([
                'target_payout_rate',
                'minimum_odds',
                'maximum_odds',
                'fixed_odds',
                'minimum_stake',
                'maximum_stake',
            ] as $key) {
                if (isset($rule[$key])) {
                    $check($rule[$key]);
                }
            }
            if (isset($rule['minimum_stake'], $rule['maximum_stake'])) { $bounds($rule['minimum_stake'], $rule['maximum_stake']); }
            foreach ($rule['stake_tiers'] ?? [] as $tier) {
                $check($tier['minimum_stake']);
                $check($tier['maximum_stake']);
                $bounds($tier['minimum_stake'], $tier['maximum_stake']);
            }
        }
        if ($payload['game_type'] !== 'UP_DOWN') {
            return;
        }
        $rules = $payload['platform_template']['rules'];
        $durations = array_column($rules, 'duration_seconds');
        sort($durations, SORT_STRING);
        if ($durations !== [
            '35',
            '40',
            '45',
            '60',
            '90',
        ]) {
            $v->errors()->add('platform_template.rules', '涨跌配置必须包含固定五房间');
        }
        $fixed = function (array $c, string $value) use ($v): void {
            if (!in_array($c['agent_editable'], [false, 0, '0'], true)) {
                $v->errors()->add('platform_template', '固定规则不可由代理修改');
            }
            foreach (['minimum_value', 'default_value', 'maximum_value'] as $key) {
                if (!\Brick\Math\BigDecimal::of($c[$key])->isEqualTo($value)) {
                    $v->errors()->add('platform_template', '下注期固定30秒');
                }
            }
        };
        foreach ($rules as $r) {
            $fixed($r['bet_open_seconds'], '30');
            $live = array_filter($r['stake_tiers'], static fn($t) => !$t['retired']);
            if (count($live) !== 1) {
                $v->errors()->add('platform_template.rules', '每房间必须有一个未退役技术档位');
            }
        }
    }
}
