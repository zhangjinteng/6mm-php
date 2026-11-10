<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;

/** Atomic Redis-only projection. Never called before the source database commits. */
final class ThemeRedisPublisher
{
    private const TEMPLATE_NAMES = ['terms'=>'agreement', 'privacy'=>'privacy', 'risk'=>'risk', 'cookies'=>'cookies', 'aml'=>'aml'];

    public function __construct(private Closure $redis, private string $prefix = 'wl:site:config:theme',
        private string $templatePrefix = 'wl:config')
    {
        foreach ([$prefix, $templatePrefix] as $value) {
            if (!preg_match('/^[A-Za-z0-9:_-]{1,160}$/D', $value) || str_ends_with($value, ':')) {
                throw new \InvalidArgumentException('Invalid configuration Redis key prefix');
            }
        }
    }

    public function templateKey(string $agreementType, string $locale): string
    {
        if (!isset(self::TEMPLATE_NAMES[$agreementType]) || !AgreementTemplateSnapshot::validLocale($locale)) {
            throw new \InvalidArgumentException('Invalid template identity');
        }
        // The five English platform templates use stable public names, independent of database IDs.
        // Retain locale isolation for any additional translations without changing the English keys.
        return $this->templatePrefix.':'.self::TEMPLATE_NAMES[$agreementType].($locale === 'en' ? '' : ':'.$locale);
    }

    public function key(int $site): string
    {
        if ($site < 1) throw new \InvalidArgumentException('Invalid theme site');
        return $this->prefix.':'.$site;
    }

    /** EVAL's argument ordering is supported by both Laravel PhpRedis and Predis connections. */
    public function publish(object $row): void
    {
        $type = $row->resource_type ?? 'theme';
        $id = (int)($row->resource_id ?? $row->site_id ?? 0);
        $locale = (string)($row->locale ?? '');
        $version = (string)$row->config_version;
        $payload = (string)$row->payload;
        $document = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        $validIdentity = match ($type) {
            'theme' => (int)$row->site_id === $id && $locale === '' && ($document->siteId ?? null) === (string)$id,
            'agreement_template' => ($row->site_id ?? null) === null && ($row->tenant_id ?? null) === null
                && AgreementTemplateSnapshot::validLocale($locale) && ($document->templateId ?? null) === (string)$id
                && ($document->locale ?? null) === $locale,
            default => false,
        };
        if ($id < 1 || !$validIdentity || !preg_match('/^[1-9][0-9]{0,18}$/D', $version) || (int)$row->schema_version !== 1
            || strlen($payload) > 1048576
            || !hash_equals((string)$row->payload_sha256, hash('sha256', $payload))) {
            throw new \UnexpectedValueException('Invalid committed configuration snapshot');
        }
        $key = $type === 'theme' ? $this->key($id) : $this->templateKey((string)($document->agreementType ?? ''), $locale);
        $args = [(string)$id, $version, (string)$row->schema_version, $payload, (string)$row->payload_sha256,
            (string)($row->previous_config_version ?? '')];
        if ($type !== 'theme') array_push($args, $type, $locale);
        $result = ($this->redis)()->eval(self::SCRIPT, 1, $key, ...$args);
        if (!in_array($result, [1, 2], true)) throw new \RuntimeException('Theme Redis write not acknowledged');
    }

    public const SCRIPT = <<<'LUA'
local key = KEYS[1]
local old = redis.call('HGET', key, 'config_version')
local incoming = ARGV[2]
if old then
    if not string.match(old, '^[1-9][0-9]*$') or string.len(old) > 19 then
        return redis.error_reply('THEME_INVALID_VERSION')
    end
    if string.len(old) > string.len(incoming) or (string.len(old) == string.len(incoming) and old > incoming) then
        return 2
    end
    if old == incoming and (redis.call('HGET', key, 'payload_sha256') ~= ARGV[5]
        or redis.call('HGET', key, 'payload') ~= ARGV[4]) then
        return redis.error_reply('THEME_VERSION_CONFLICT')
    end
end
redis.call('HSET', key, 'config_version', incoming, 'schema_version', ARGV[3],
    'payload', ARGV[4], 'payload_sha256', ARGV[5], 'previous_config_version', ARGV[6])
if ARGV[7] == 'agreement_template' then
    redis.call('HSET', key, 'template_id', ARGV[1], 'locale', ARGV[8])
else
    redis.call('HSET', key, 'site_id', ARGV[1])
end
redis.call('PERSIST', key)
return 1
LUA;
}
