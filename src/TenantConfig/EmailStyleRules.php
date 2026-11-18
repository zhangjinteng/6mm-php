<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

/** Static email CSS subset supplied by common/emailtemplate (0a4f27233). */
final class EmailStyleRules
{
    public static function validate(string $style): void
    {
        if (strlen($style) > 2048) self::fail();
        $seen = [];
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') continue;
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) self::fail();
            [$name, $value] = array_map('trim', $parts);
            if (isset($seen[$name]) || count($seen) >= 24 || !self::allowed($name, $value)) self::fail();
            $seen[$name] = true;
        }
    }

    private static function pixels(string $value, int $min, int $max): bool
    {
        if ($min === 0 && $value === '0') return true;
        return preg_match('/^([0-9]+)px$/D', $value, $m) === 1 && (int)$m[1] >= $min && (int)$m[1] <= $max;
    }

    private static function allowed(string $name, string $value): bool
    {
        $choices = [
            'font-family' => ['Arial, Helvetica, sans-serif', 'Courier New, monospace'],
            'font-weight' => ['400', '500', '600', '700'],
            'text-align' => ['left', 'center', 'right'], 'vertical-align' => ['top', 'middle', 'bottom'],
            'text-decoration' => ['none', 'underline'], 'display' => ['block', 'inline-block'],
            'border-collapse' => ['collapse', 'separate'], 'table-layout' => ['fixed'], 'border-spacing' => ['0'],
        ];
        if (isset($choices[$name])) return in_array($value, $choices[$name], true);
        if (in_array($name, ['color', 'background-color'], true)) {
            return $value === 'transparent' || preg_match('/^#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/D', $value) === 1;
        }
        $ranges = ['font-size' => [8, 64], 'line-height' => [10, 96], 'height' => [1, 256],
            'letter-spacing' => [0, 8], 'border-radius' => [0, 32]];
        if (isset($ranges[$name])) return self::pixels($value, ...$ranges[$name]);
        if (in_array($name, ['width', 'max-width'], true)) return $value === '100%' || self::pixels($value, 1, 1024);
        if (in_array($name, ['padding', 'margin'], true)) {
            $values = preg_split('/\s+/u', $value);
            if (!$values || count($values) > 4) return false;
            foreach ($values as $part) if (!self::pixels($part, 0, 64)) return false;
            return true;
        }
        if (preg_match('/^(?:padding|margin)-(?:top|right|bottom|left)$/D', $name)) return self::pixels($value, 0, 64);
        if (preg_match('/^border(?:-(?:top|right|bottom|left))?$/D', $name)) {
            return preg_match('/^(0|[0-9]+px) solid (#[a-fA-F0-9]{3}|#[a-fA-F0-9]{6})$/D', $value, $m) === 1
                && self::pixels($m[1], 0, 4);
        }
        return false;
    }

    private static function fail(): never
    {
        throw new EmailConfigException('邮件 CSS 属性、值、数量或长度不符合允许规则');
    }
}
