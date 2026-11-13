<?php
declare(strict_types=1);
namespace SixMm\Shared\RpcTickets;

final class TicketPayload
{
    public static function normalize(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, 'serializeToJsonString')) {
            $value = json_decode($value->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $key = is_string($key) ? strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1_$2',
                    preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key))) : $key;
                if (is_string($key) && preg_match('/authorization|password|passwd|passphrase|secret|token|api_?key|private_?key|credential|cookie/i', $key)) {
                    $result[$key] = '[REDACTED]';
                } else {
                    $item = self::normalize($item);
                    $result[$key] = is_string($key) && preg_match('/(^|_)(id|version)$/D', $key) && is_int($item) ? (string) $item : $item;
                }
            }
            return $result;
        }
        if (is_string($value)) return preg_replace('/Bearer\s+[^\s"\'<>]+/i', 'Bearer [REDACTED]', $value);
        if ($value === null || is_scalar($value)) return $value;
        return '[unsupported '.get_debug_type($value).']';
    }

    public static function response(mixed $value): mixed
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return strlen($json) <= 1048576 ? $value : ['truncated'=>true,'bytes'=>strlen($json),'sha256'=>hash('sha256',$json)];
    }
}
