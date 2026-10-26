<?php

declare(strict_types=1);

namespace SixMm\Shared\Prediction\Configuration;

final class GridConfigInput
{
    private const MAX_INT64 = '9223372036854775807';
    private const MAX_MONEY = '92233720368.54775807';
    private const ACTOR_KEYS = ['operator_id', 'root_agent_id', 'source_service'];
    private const COMMAND_KEYS = ['based_on_layout_version', 'based_on_site_version', 'changes', 'client_request_id', 'reason', 'site_id', 'symbol'];
    public const IDENTITY_KEYS = ['based_on_config_id', 'based_on_default_resolution_epoch', 'based_on_default_version'];
    public const LAYOUT_KEYS = ['price_band_width', 'column_duration_seconds', 'price_decimals'];
    private const CHANGE_KEYS = ['enabled', 'max_multiplier', 'max_order_amount', 'min_multiplier', 'min_order_amount', 'target_rtp'];

    public static function actor(array $actor): array
    {
        self::exactKeys($actor, self::ACTOR_KEYS);
        if (!is_string($actor['source_service']) || !in_array($actor['source_service'], ['platform_admin', 'agent_admin'], true)) {
            throw GridConfigException::invalidInput();
        }
        if (!is_string($actor['operator_id']) || !self::isPositiveInt64($actor['operator_id'])) {
            throw GridConfigException::invalidInput();
        }
        if ($actor['source_service'] === 'platform_admin') {
            if ($actor['root_agent_id'] !== null) {
                throw GridConfigException::invalidInput();
            }
        } elseif (!is_string($actor['root_agent_id']) || !self::isPositiveInt64($actor['root_agent_id'])) {
            throw GridConfigException::invalidInput();
        }
        return $actor;
    }

    public static function command(array $actor, array $command): array
    {
        $actor = self::actor($actor);
        if (($command['based_on_site_version'] ?? null) === '0') { return self::creation($actor, $command); }
        $identity = array_intersect_key($command, array_flip(self::IDENTITY_KEYS));
        $keys = self::COMMAND_KEYS;
        if ($identity !== []) {
            $keys = array_merge($keys, self::IDENTITY_KEYS);
            sort($keys, SORT_STRING);
            foreach (self::IDENTITY_KEYS as $field) {
                if (!isset($command[$field]) || !is_string($command[$field]) || !self::isPositiveInt64($command[$field])) {
                    throw GridConfigException::invalidInput();
                }
            }
        }
        self::exactKeys($command, $keys);
        self::site($command['site_id'] ?? null);
        self::symbol($command['symbol'] ?? null);
        foreach (['based_on_layout_version', 'based_on_site_version'] as $field) {
            if (!is_string($command[$field]) || !self::isPositiveInt64($command[$field])) {
                throw GridConfigException::invalidInput();
            }
        }
        if (!is_string($command['client_request_id']) || preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $command['client_request_id']) !== 1) {
            throw GridConfigException::invalidInput();
        }
        if (!is_string($command['reason']) || strlen($command['reason']) < 1 || strlen($command['reason']) > 500 ||
            strpos($command['reason'], "\0") !== false || preg_match('//u', $command['reason']) !== 1) {
            throw GridConfigException::invalidInput();
        }
        if (!is_array($command['changes']) || $command['changes'] === []) {
            throw GridConfigException::invalidInput();
        }
        $changeKeys = array_keys($command['changes']);
        foreach ($changeKeys as $key) {
            if (!is_string($key) || !in_array($key, array_merge(self::CHANGE_KEYS, self::LAYOUT_KEYS), true)) {
                throw GridConfigException::invalidInput();
            }
        }
        if ($actor['source_service'] === 'agent_admin' &&
            (array_key_exists('min_multiplier', $command['changes']) || array_key_exists('max_multiplier', $command['changes']) || array_intersect_key($command['changes'], array_flip(self::LAYOUT_KEYS)) !== [])) {
            throw new GridConfigException('FIELD_FORBIDDEN', 'Grid configuration field is not editable');
        }
        $normalized = $command;
        foreach ($command['changes'] as $field => $value) {
            if (in_array($field, self::LAYOUT_KEYS, true)) {
                $normalized['changes'][$field] = GridLayoutInput::field($field, $value);
                continue;
            }
            if ($field === 'enabled') {
                if (!is_bool($value)) {
                    throw GridConfigException::invalidInput();
                }
                $normalized['changes'][$field] = $value;
                continue;
            }
            $scale = str_contains($field, 'order_amount') ? 8 : (str_contains($field, 'multiplier') ? 2 : null);
            $normalized['changes'][$field] = self::decimal($value, $scale);
        }
        ksort($normalized['changes'], SORT_STRING);
        return $normalized;
    }

    private static function creation(array $actor, array $command): array
    {
        if ($actor['source_service'] !== 'platform_admin') { throw GridConfigException::invalidInput(); }
        self::exactKeys($command, self::COMMAND_KEYS);
        $version = $command['based_on_layout_version'];
        if (!is_string($version) || ($version !== '0' && !self::isPositiveInt64($version))) { throw GridConfigException::invalidInput(); }
        $layoutKeys = ['row_count', 'column_count', 'column_duration_seconds', 'price_band_width'];
        if ($version === '0' && is_array($command['changes'] ?? null) && array_key_exists('price_decimals', $command['changes'])) { $layoutKeys[] = 'price_decimals'; }
        $expected = $version === '0' ? array_merge(self::CHANGE_KEYS, $layoutKeys) : self::CHANGE_KEYS;
        sort($expected, SORT_STRING);
        if (!is_array($command['changes']) || ($command['changes']['enabled'] ?? null) !== false) { throw GridConfigException::invalidInput(); }
        self::exactKeys($command['changes'], $expected);
        // Reuse the same scalar and identity validation without changing historical commands.
        $probe = $command;
        $probe['based_on_site_version'] = $probe['based_on_layout_version'] = '1';
        $probe['changes'] = array_intersect_key($command['changes'], array_flip(self::CHANGE_KEYS));
        $normalized = self::command($actor, $probe);
        $normalized['based_on_site_version'] = '0';
        $normalized['based_on_layout_version'] = $version;
        $normalized['changes'] = self::mergeAndValidate($normalized['changes'], []);
        if ($version === '0') {
            $layout = array_intersect_key($command['changes'], array_flip($layoutKeys));
            if (array_key_exists('price_decimals', $layout)) { $layout['price_decimals'] = GridLayoutInput::field('price_decimals', $layout['price_decimals']); }
            GridLayoutInput::geometry($layout);
            $layout['price_band_width'] = GridLayoutInput::field('price_band_width', $layout['price_band_width'], 8);
            $normalized['changes'] += $layout;
        }
        ksort($normalized['changes'], SORT_STRING);
        return $normalized;
    }

    public static function mergeAndValidate(array $current, array $changes): array
    {
        $required = self::CHANGE_KEYS;
        sort($required, SORT_STRING);
        $keys = array_keys($current);
        sort($keys, SORT_STRING);
        if ($keys !== $required || !is_bool($current['enabled'])) {
            throw new GridConfigException('DATA_INTEGRITY', 'Grid configuration data is invalid');
        }
        $merged = $current;
        foreach (self::CHANGE_KEYS as $field) {
            if ($field === 'enabled') {
                continue;
            }
            $scale = str_contains($field, 'order_amount') ? 8 : (str_contains($field, 'multiplier') ? 2 : null);
            $merged[$field] = self::decimal($merged[$field], $scale);
        }
        foreach ($changes as $field => $value) {
            $merged[$field] = $value;
        }
        if (bccomp($merged['target_rtp'], '0', self::scale($merged['target_rtp'])) <= 0 ||
            bccomp($merged['target_rtp'], '1', self::scale($merged['target_rtp'])) >= 0 ||
            bccomp($merged['min_multiplier'], '1', self::scale($merged['min_multiplier'])) < 0 ||
            bccomp($merged['max_multiplier'], $merged['min_multiplier'], self::maxScale($merged['max_multiplier'], $merged['min_multiplier'])) < 0 ||
            bccomp($merged['min_order_amount'], '0', self::scale($merged['min_order_amount'])) <= 0 ||
            bccomp($merged['max_order_amount'], $merged['min_order_amount'], self::maxScale($merged['max_order_amount'], $merged['min_order_amount'])) < 0 ||
            bccomp($merged['max_order_amount'], self::MAX_MONEY, self::maxScale($merged['max_order_amount'], self::MAX_MONEY)) > 0) {
            throw GridConfigException::invalidInput();
        }
        $productScale = self::scale($merged['max_order_amount']) + self::scale($merged['max_multiplier']);
        $payout = bcmul($merged['max_order_amount'], $merged['max_multiplier'], $productScale);
        if (bccomp($payout, self::MAX_MONEY, max($productScale, 8)) > 0) {
            throw GridConfigException::invalidInput();
        }
        return $merged;
    }

    public static function requestHash(array $actor, array $command): string
    {
        $actor = self::actor($actor);
        $actor = [
            'source_service' => $actor['source_service'],
            'operator_id' => $actor['operator_id'],
            'root_agent_id' => $actor['root_agent_id'],
        ];
        $changes = $command['changes'];
        ksort($changes, SORT_STRING);
        $payload = [
            'actor' => $actor,
            'site_id' => $command['site_id'],
            'symbol' => $command['symbol'],
            'based_on_layout_version' => $command['based_on_layout_version'],
            'based_on_site_version' => $command['based_on_site_version'],
            'client_request_id' => $command['client_request_id'],
            'reason' => $command['reason'],
            'changes' => $changes,
        ];
        // Keep the historical payload byte-for-byte for existing UNKNOWN receipts.
        foreach (self::IDENTITY_KEYS as $field) {
            if (array_key_exists($field, $command)) { $payload[$field] = $command[$field]; }
        }
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== $expected) {
            throw GridConfigException::invalidInput();
        }
    }

    private static function site($siteId): void
    {
        if (!is_string($siteId) || $siteId === '' || strlen($siteId) > 64 || strpos($siteId, "\0") !== false ||
            preg_match('//u', $siteId) !== 1 || preg_match('/^\s|\s$/u', $siteId) === 1) {
            throw GridConfigException::invalidInput();
        }
    }

    private static function symbol($symbol): void
    {
        if (!is_string($symbol) || preg_match('/^[A-Z0-9]{2,32}$/D', $symbol) !== 1) {
            throw GridConfigException::invalidInput();
        }
    }

    private static function decimal($value, ?int $maxScale): string
    {
        if (!is_string($value) || strlen($value) > 128 || preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw GridConfigException::invalidInput();
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        if ($maxScale !== null && strlen($fraction) > $maxScale) {
            throw GridConfigException::invalidInput();
        }
        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    private static function isPositiveInt64(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/D', $value) === 1 &&
            (strlen($value) < strlen(self::MAX_INT64) || (strlen($value) === strlen(self::MAX_INT64) && strcmp($value, self::MAX_INT64) <= 0));
    }

    private static function scale(string $value): int
    {
        $position = strpos($value, '.');
        return $position === false ? 0 : strlen($value) - $position - 1;
    }

    private static function maxScale(string $left, string $right): int
    {
        return max(self::scale($left), self::scale($right));
    }
}
