<?php

namespace SixMm\Shared\Prediction\Configuration;

final class ConfigurationInteger
{
    public static function canonical($value, bool $positive = false): ?string
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $value = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || ($positive && $value === '0')) {
            return null;
        }
        return strlen($value) < 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') <= 0) ? $value : null;
    }
}
