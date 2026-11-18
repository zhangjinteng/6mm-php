<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use Closure;
use stdClass;

/** Read-only integrity checks for references retained by non-email publication. */
final class PublishedEmailReference
{
    public function __construct(private Closure $table, private Closure $reject) {}

    private function fail(string $message): never
    {
        ($this->reject)($message);
        throw new \LogicException($message);
    }

    public function schemaVersion(stdClass $site): int
    {
        $ref = $site->emailTemplateRef ?? null;
        if ($ref === null) return 4;
        if (!$ref instanceof stdClass) $this->fail('邮件模板引用格式无效');
        $this->validateIdentity($ref);
        $storage = property_exists($ref, 'storage') ? $ref->storage : '';
        return match ($storage) {
            '' => 4,
            'rows' => 5,
            default => $this->fail('不支持的邮件模板存储格式'),
        };
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function validateIdentity(stdClass $ref): void
    {
        if (!is_int($ref->version ?? null) || $ref->version < 1
            || !is_string($ref->siteName ?? null) || trim($ref->siteName) === '' || mb_strlen($ref->siteName) > 128
            || !is_string($ref->defaultLocale ?? null) || $ref->defaultLocale === '' || strlen($ref->defaultLocale) > 35
            || !$this->hash($ref->sha256 ?? null)) $this->fail('邮件模板引用身份或摘要格式无效');
    }

    private function object(string $raw): stdClass
    {
        try { $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { $this->fail('邮件发布内容不是有效 JSON'); }
        if (!$value instanceof stdClass) $this->fail('邮件发布内容格式无效');
        return $value;
    }

    /** Never reserialize immutable bodies to calculate their digest. */
    public function validateRows(int $site, stdClass $ref): void
    {
        $this->validateIdentity($ref);
        if (($ref->storage ?? null) !== 'rows') $this->fail('分行邮件模板引用格式无效');

        $entry = ($this->table)('wl_site_email_template_versions')->where('site_id', $site)
            ->where('template_version', $ref->version)->first();
        if (!$entry || !is_string($entry->payload) || strlen($entry->payload) > 131072
            || !$this->hash($entry->payload_sha256) || !hash_equals($ref->sha256, $entry->payload_sha256)
            || !hash_equals($ref->sha256, hash('sha256', $entry->payload))) $this->fail('邮件发布清单缺失或摘要校验失败');
        $manifest = $this->object($entry->payload);
        if (($manifest->version ?? null) !== $ref->version || ($manifest->siteName ?? null) !== $ref->siteName
            || ($manifest->defaultLocale ?? null) !== $ref->defaultLocale || !is_array($manifest->items ?? null)
            || count($manifest->items) < 1 || count($manifest->items) > 256) $this->fail('邮件引用与发布清单不一致');

        $expected = [];
        foreach ($manifest->items as $item) {
            if (!$item instanceof stdClass || !is_string($item->purpose ?? null) || !is_string($item->locale ?? null)
                || !in_array($item->purpose, ['register', 'login', 'password_set', 'password_reset'], true)
                || $item->locale === '' || strlen($item->locale) > 35 || !$this->hash($item->sha256 ?? null)
                || array_diff(array_keys(get_object_vars($item)), ['purpose', 'locale', 'sha256'])) $this->fail('邮件发布清单条目无效');
            $key = $item->purpose."\0".$item->locale;
            if (isset($expected[$key])) $this->fail('邮件发布清单存在重复条目');
            $expected[$key] = $item->sha256;
        }

        // Bound the query before loading body payloads; extra records also fail closed.
        $rows = ($this->table)('wl_site_email_template_item_versions')->where('site_id', $site)
            ->where('template_version', $ref->version)->limit(257)->get();
        if ($rows->count() !== count($expected)) $this->fail('邮件发布正文缺失或存在额外条目');
        $bytes = 0;
        foreach ($rows as $row) {
            $key = $row->purpose."\0".$row->locale;
            if (!isset($expected[$key]) || !is_string($row->payload) || strlen($row->payload) > 262144
                || !$this->hash($row->payload_sha256) || !hash_equals($expected[$key], $row->payload_sha256)
                || !hash_equals($expected[$key], hash('sha256', $row->payload))) $this->fail('邮件发布正文摘要校验失败');
            $bytes += strlen($row->payload);
            if ($bytes > 4194304) $this->fail('邮件发布正文超过总容量限制');
            $body = $this->object($row->payload);
            if (($body->purpose ?? null) !== $row->purpose || ($body->locale ?? null) !== $row->locale
                || !is_string($body->subject ?? null) || !is_string($body->textBody ?? null)
                || (isset($body->htmlBody) && !is_string($body->htmlBody))) $this->fail('邮件发布正文身份或格式无效');
            unset($expected[$key]);
        }
        if ($expected) $this->fail('邮件发布正文缺失');
    }
}
