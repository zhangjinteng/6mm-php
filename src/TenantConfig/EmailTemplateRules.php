<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

/** Validation never sanitizes/replaces an existing site's template text. */
final class EmailTemplateRules
{
    public const PURPOSES = ['register', 'login', 'password_set', 'password_reset'];

    public static function json(mixed $value): string
    {
        // Go encoding/json uses lowercase HTML escapes. Replace literal characters only:
        // lowercasing escape-like text would corrupt a body's literal "\\u003C" text.
        return str_replace(['<', '>', '&'], ['\\u003c', '\\u003e', '\\u0026'],
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function locale(string $raw): string
    {
        $raw = str_replace('_', '-', trim($raw));
        $grammar = '/^(?:[A-Za-z]{2,3}(?:-[A-Za-z]{3}){0,3}|[A-Za-z]{4}|[A-Za-z]{5,8})'
            .'(?:-[A-Za-z]{4})?(?:-(?:[A-Za-z]{2}|[0-9]{3}))?'
            .'(?:-(?:[A-Za-z0-9]{5,8}|[0-9][A-Za-z0-9]{3}))*'
            .'(?:-[0-9A-WY-Za-wy-z](?:-[A-Za-z0-9]{2,8})+)*(?:-[xX](?:-[A-Za-z0-9]{1,8})+)?$/D';
        if (strlen($raw) > 35 || !preg_match($grammar, $raw)) {
            throw new EmailConfigException('请输入有效的默认语言，例如 zh-CN、en');
        }
        // ICU canonicalize() emits @keyword syntax for BCP-47 extensions. Keep the
        // language-tag representation so private/Unicode extensions remain readable.
        $parts = explode('-', $raw);
        $parts[0] = ['iw' => 'he', 'in' => 'id', 'ji' => 'yi'][strtolower($parts[0])] ?? strtolower($parts[0]);
        $base = [$parts[0]]; $extensions = []; $extension = null; $private = false; $variants = [];
        foreach ($parts as $i => $part) {
            if ($i === 0) continue;
            if ($private) { $extensions['x'][] = strtolower($part); continue; }
            if (strlen($part) === 1) {
                $part = strtolower($part);
                if (isset($extensions[$part])) throw new EmailConfigException('语言标签包含重复扩展');
                $extensions[$part] = []; $extension = $part; $private = $part === 'x';
                continue;
            }
            if ($extension !== null) { $extensions[$extension][] = strtolower($part); continue; }
            if (strlen($part) >= 5 || (strlen($part) === 4 && ctype_digit($part[0]))) {
                $variant = strtolower($part);
                if (isset($variants[$variant])) throw new EmailConfigException('语言标签包含重复变体');
                $variants[$variant] = true;
            }
            $part = strlen($part) === 4 && ctype_alpha($part) ? ucfirst(strtolower($part))
                : (strlen($part) === 2 && ctype_alpha($part) ? strtoupper($part) : strtolower($part));
            $base[] = $part;
        }
        ksort($extensions, SORT_STRING);
        foreach ($extensions as $singleton => $subtags) array_push($base, (string)$singleton, ...$subtags);
        return implode('-', $base);
    }

    /** Logo validation; templates separately validate every static HTML resource. */
    public static function url(string $url, array $hosts): void
    {
        self::validateWith(static fn () => EmailTemplateValidator::validateLogo($url, $hosts));
    }

    /** Keep control-plane identity rules outside the upstream body validator. */
    public static function template(array $body, array $hosts, string $logoUrl = '', bool $rowStorage = true): void
    {
        if (array_diff(array_keys($body), ['purpose', 'locale', 'subject', 'textBody', 'htmlBody'])
            || !in_array($body['purpose'] ?? null, self::PURPOSES, true)
            || !is_string($body['locale'] ?? null) || self::locale($body['locale']) !== $body['locale']) {
            throw new EmailConfigException('邮件模板用途或语言格式无效');
        }
        self::validateWith(static fn () => EmailTemplateValidator::validate($body, $hosts, $logoUrl, $rowStorage));
    }

    private static function validateWith(callable $operation): void
    {
        try { $operation(); }
        catch (\InvalidArgumentException $e) {
            $reason = $e->getMessage();
            $message = match (true) {
                preg_match('/email HTML tag|HTML markup|unclosed HTML tag|invalid closing tag/', $reason) === 1 => '邮件 HTML 包含不允许的标签或格式',
                str_contains($reason, 'style') => '邮件 CSS 属性、值、数量或长度不符合允许规则',
                preg_match('/attribute|image size|email role|email alignment|table spacing/', $reason) === 1 => '邮件 HTML 包含不允许的属性或属性值',
                preg_match('/resource|HTTPS|authority|URL|host|IPv6/', $reason) === 1 => '邮件图片必须使用已允许主机的 HTTPS 地址，且不能含用户信息或显式端口',
                str_contains($reason, 'logo') => '邮件 Logo 变量或地址格式无效',
                str_contains($reason, 'HTML') => '邮件 HTML 包含不允许的标签或格式',
                default => '邮件模板格式或变量不符合允许规则',
            };
            throw new EmailConfigException($message.'：'.$reason, 0, $e);
        }
    }

    /** Public presets are copied only for genuinely missing/empty site templates. */
    public static function defaults(): array
    {
        // Keep upstream content as a source asset; adapt only the managed header.
        $items = json_decode(file_get_contents(__DIR__.'/presets/email-templates.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($items as &$body) $body['htmlBody'] = EmailHeaderLayout::html($body['htmlBody']);
        return $items;
    }
}
