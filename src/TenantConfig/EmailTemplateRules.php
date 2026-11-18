<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

/** Validation never sanitizes/replaces an existing site's template text. */
final class EmailTemplateRules
{
    public const PURPOSES = ['register', 'login', 'password_set', 'password_reset'];
    private const VARIABLES = ['siteName', 'purposeLabel', 'code', 'validMinutes', 'expiresAt', 'logoUrl'];

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

    public static function url(string $url, array $hosts): void
    {
        if ($url === '') return;
        $parts = parse_url($url);
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !filter_var($url, FILTER_VALIDATE_URL) || !$parts || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !in_array(strtolower($parts['host'] ?? ''), $hosts, true)) {
            throw new EmailConfigException('邮件图片必须使用已允许主机的 HTTPS 地址');
        }
    }

    private static function variables(string $text, bool $subject = false): void
    {
        preg_match_all('/{{\s*([^{}]+?)\s*}}/u', $text, $matches);
        $allowed = $subject ? ['siteName', 'purposeLabel'] : self::VARIABLES;
        foreach ($matches[1] as $variable) {
            if (!in_array($variable, $allowed, true)) throw new EmailConfigException('邮件模板包含不支持的变量');
        }
        if (str_contains(preg_replace('/{{\s*[^{}]+?\s*}}/u', '', $text), '{{')) {
            throw new EmailConfigException('邮件模板变量格式无效');
        }
    }

    public static function template(array $body, array $hosts): void
    {
        if (array_diff(array_keys($body), ['purpose', 'locale', 'subject', 'textBody', 'htmlBody'])
            || !in_array($body['purpose'] ?? null, self::PURPOSES, true)
            || !is_string($body['locale'] ?? null) || self::locale($body['locale']) !== $body['locale']
            || !is_string($body['subject'] ?? null) || trim($body['subject']) === '' || mb_strlen($body['subject']) > 200
            || preg_match('/[\r\n\x00]/', $body['subject'])
            || !is_string($body['textBody'] ?? null) || trim($body['textBody']) === '' || strlen($body['textBody']) > 8192
            || (isset($body['htmlBody']) && !is_string($body['htmlBody'])) || strlen($body['htmlBody'] ?? '') > 32768) {
            throw new EmailConfigException('邮件模板用途、语言或正文格式无效');
        }
        self::variables($body['subject'], true);
        self::variables($body['textBody']);
        self::variables($body['htmlBody'] ?? '');
        if (preg_match('/{{\s*logoUrl\s*}}/u', $body['subject'].$body['textBody'])) throw new EmailConfigException('Logo 变量只能用于图片 src');
        $html = $body['htmlBody'] ?? '';
        if ($html === '') return;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($dom->getElementsByTagName('*') as $node) {
                $tag = strtolower($node->nodeName);
                if (in_array($tag, ['script','iframe','object','embed','form','input','button','textarea','svg','math','base','link'], true)) {
                    throw new EmailConfigException('邮件 HTML 包含不允许的标签');
                }
                foreach ($node->attributes as $attribute) {
                    $name = strtolower($attribute->name);
                    $value = $attribute->value;
                    if (str_starts_with($name, 'on') || $name === 'srcdoc'
                        || preg_match('/(?:javascript\s*:|vbscript\s*:|expression\s*\(|@import|url\s*\()/i', $value)) {
                        throw new EmailConfigException('邮件 HTML 包含不安全的属性');
                    }
                    if (str_contains($value, '{{logoUrl}}') && !($tag === 'img' && $name === 'src' && $value === '{{logoUrl}}')) {
                        throw new EmailConfigException('Logo 变量只能作为 img 的完整 src');
                    }
                    if ($name === 'src' && !($tag === 'img' && $value === '{{logoUrl}}')) self::url($value, $hosts);
                    if ($name === 'href' && !preg_match('/^https:\/\//D', $value)) throw new EmailConfigException('邮件链接必须使用 HTTPS');
                }
            }
            // Remove only allowed occurrences for checking, never rewrite the stored HTML.
            $withoutLogo = preg_replace('/(<img\b[^>]*\bsrc\s*=\s*["\']){{logoUrl}}(["\'])/i', '$1$2', $html);
            if (preg_match('/{{\s*logoUrl\s*}}/u', $withoutLogo)) throw new EmailConfigException('Logo 变量位置无效');
            if (preg_match('/(?:expression\s*\(|@import|url\s*\()/i', $html)) throw new EmailConfigException('邮件 CSS 包含不安全的资源引用');
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    /** Public presets are copied only for genuinely missing/empty site templates. */
    public static function defaults(): array
    {
        $items = [];
        foreach (self::PURPOSES as $purpose) foreach (['en', 'zh-CN'] as $locale) {
            $english = $locale === 'en';
            $text = $english
                ? "Your {{siteName}} {{purposeLabel}} code is {{code}}.\nValid for {{validMinutes}} minutes, until {{expiresAt}}.\nIf you did not request this code, ignore this email."
                : "您的 {{siteName}} {{purposeLabel}}验证码是 {{code}}。\n有效期 {{validMinutes}} 分钟，到期时间 {{expiresAt}}。\n如非本人操作，请忽略此邮件。";
            $items[] = ['purpose' => $purpose, 'locale' => $locale, 'subject' => '{{siteName}} - {{purposeLabel}}', 'textBody' => $text,
                'htmlBody' => '<html><body><table role="presentation"><tr><td><img src="{{logoUrl}}" alt="" width="120"></td></tr>'
                    .'<tr><td><h1>{{siteName}}</h1><p>{{purposeLabel}}</p><p><strong>{{code}}</strong></p><p>'
                    .($english ? 'Valid for {{validMinutes}} minutes, until {{expiresAt}}.' : '有效期 {{validMinutes}} 分钟，到期时间 {{expiresAt}}。')
                    .'</p><p>'.($english ? 'If you did not request this code, ignore this email.' : '如非本人操作，请忽略此邮件。')
                    .'</p></td></tr></table></body></html>'];
        }
        usort($items, static fn ($a, $b) => [$a['purpose'], $a['locale']] <=> [$b['purpose'], $b['locale']]);
        return $items;
    }
}
