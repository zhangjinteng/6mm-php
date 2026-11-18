<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use InvalidArgumentException;

/**
 * 邮件正文的保存前校验；PHP 8.1+，无需框架、DOM或mbstring。
 * 依据 Go emailtemplate（0a4f27233）；严格HTML子集，不替代受控发布器。
 * 校验原文，不清洗、不重写、不联网；失败抛出 InvalidArgumentException。
 */
final class EmailTemplateValidator
{
    private const TAGS = ['p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'table', 'tbody', 'tr', 'td', 'a', 'img'];
    private const TOKEN = '/\{\{([a-zA-Z]+)\}\}/';
    private const WS = "\x09\x0a\x0c\x0d ";

    /**
     * $template 包含 subject、textBody，以及可选 htmlBody。
     * $allowedHosts 必须来自可信服务配置，不能由浏览器提交。
     * $logoUrl 使用合并保存补丁后的实际值；省略字段不等于清空Logo。
     * 本方法不检查站点权限、版本、语言规范化或模板集合；发布器继续负责。
     */
    public static function validate(array $template, array $allowedHosts, string $logoUrl = '', bool $rowStorage = true): void
    {
        foreach (['subject', 'textBody', 'htmlBody'] as $key) {
            self::require(isset($template[$key]) && is_string($template[$key]) || ($key === 'htmlBody' && !array_key_exists($key, $template)), "$key must be a string");
        }
        foreach ($allowedHosts as $host) {
            self::require(is_string($host) && $host !== '', 'invalid resource host configuration');
        }
        $subject = $template['subject'];
        $text = $template['textBody'];
        $html = $template['htmlBody'] ?? '';
        self::require(strlen($subject) <= 800, 'email subject too large');
        self::utf8($subject);
        self::utf8($text);
        self::utf8($html);
        self::require(preg_match('/[^\s\p{Z}]/u', $subject) === 1 && preg_match('/\p{Cc}/u', $subject) !== 1, 'invalid email subject');
        self::require(preg_match_all('/./us', $subject) <= 200, 'email subject exceeds 200 characters');
        self::require(strlen($text) <= 8192 && strlen($html) <= 32768, 'email body too large');
        self::require(!str_contains($html, "\0"), 'NUL in HTML is unsupported');
        self::require($rowStorage || ($logoUrl === '' && !str_contains($html, '{{logoUrl}}')), 'logo requires row storage');
        self::validateLogo($logoUrl, $allowedHosts);
        foreach ([$subject, $text, $html] as $index => $value) {
            if ($index === 2 && $value === '') {
                continue;
            }
            preg_match_all(self::TOKEN, $value, $tokens);
            foreach ($tokens[1] as $name) {
                $allowed = ['siteName', 'purposeLabel'];
                if ($index > 0) {
                    $allowed = array_merge($allowed, ['code', 'validMinutes', 'expiresAt']);
                }
                if ($index === 2 && $rowStorage) {
                    $allowed[] = 'logoUrl';
                }
                self::require(in_array($name, $allowed, true), 'unsupported email variable: ' . $name);
            }
            $stripped = preg_replace(self::TOKEN, '', $value);
            self::require(!str_contains($stripped, '{{') && !str_contains($stripped, '}}'), 'invalid email placeholder');
            if ($index > 0) {
                self::require(str_contains($value, '{{code}}') && (str_contains($value, '{{expiresAt}}') || str_contains($value, '{{validMinutes}}')), 'code and expiry variables required');
            }
        }
        self::html($html, $allowedHosts, $rowStorage);
    }

    public static function validateLogo(string $url, array $allowedHosts): void
    {
        if ($url === '') {
            return;
        }
        self::require(strlen($url) <= 2048 && !str_contains($url, '{') && !str_contains($url, '}'), 'invalid logo URL');
        self::resource($url, $allowedHosts);
    }

    /** 校验单个style属性；不接受任意CSS。 */
    public static function validateStyle(string $style): void
    {
        self::utf8($style);
        self::require(strlen($style) <= 2048, 'email style too large');
        $seen = [];
        foreach (explode(';', $style) as $part) {
            $part = self::trimSpace($part);
            if ($part === '') {
                continue;
            }
            $pair = explode(':', $part, 2);
            self::require(count($pair) === 2, 'invalid email style');
            [$key, $value] = array_map([self::class, 'trimSpace'], $pair);
            self::require(!isset($seen[$key]) && count($seen) < 24 && self::styleValue($key, $value), 'unsupported email style: ' . $key);
            $seen[$key] = true;
        }
    }

    private static function html(string $html, array $hosts, bool $rowStorage): void
    {
        $offset = 0;
        $length = strlen($html);
        while ($offset < $length) {
            if ($html[$offset] !== '<') {
                $end = strpos($html, '<', $offset);
                $end = $end === false ? $length : $end;
                $rawText = substr($html, $offset, $end - $offset);
                self::require(preg_match('/&(?!\#(?:[0-9]+|[xX][0-9a-fA-F]+);|[A-Za-z][A-Za-z0-9]+;)/', $rawText) !== 1, 'escape HTML ampersands as &amp;');
                $text = self::decode($rawText);
                self::require(!str_contains($text, '{{logoUrl}}'), 'logo variable requires image src');
                $offset = $end;
                continue;
            }
            // 按位置扫描并保留每个属性；不使用会丢弃重复属性或自动修复标签的DOM解析。
            self::require(preg_match('/\G<(\/?)([A-Za-z][A-Za-z0-9]*)(?=[\x09\x0a\x0c\x0d \/>])/', $html, $m, 0, $offset) === 1, 'unsupported or malformed HTML markup');
            $closing = $m[1] === '/';
            $tag = strtolower($m[2]);
            self::require(in_array($tag, self::TAGS, true), 'unsupported email HTML tag: ' . $tag);
            $offset += strlen($m[0]);
            $seen = [];
            while (true) {
                $space = strspn($html, self::WS, $offset);
                $offset += $space;
                self::require($offset < $length, 'unclosed HTML tag');
                if ($html[$offset] === '>') {
                    ++$offset;
                    break;
                }
                if (substr($html, $offset, 2) === '/>') {
                    self::require(!$closing, 'invalid closing tag');
                    $offset += 2;
                    break;
                }
                self::require(!$closing && $space > 0, 'malformed HTML attribute');
                self::require(preg_match('/\G([A-Za-z][A-Za-z0-9:-]*)[\x09\x0a\x0c\x0d ]*=[\x09\x0a\x0c\x0d ]*([\'\"])/', $html, $a, 0, $offset) === 1, 'attribute values must be quoted');
                $key = strtolower($a[1]);
                self::require(!isset($seen[$key]), 'duplicate HTML attribute: ' . $key);
                $seen[$key] = true;
                $offset += strlen($a[0]);
                $end = strpos($html, $a[2], $offset);
                self::require($end !== false, 'unclosed HTML attribute');
                $raw = substr($html, $offset, $end - $offset);
                $offset = $end + 1;
                // 要求属性内字符引用完整，避免PHP与HTML5对省略分号的实体解释不同。
                self::require(preg_match('/&(?!\#(?:[0-9]+|[xX][0-9a-fA-F]+);|[A-Za-z][A-Za-z0-9]+;)/', $raw) !== 1, 'escape attribute ampersands as &amp;');
                $value = self::decode($raw);
                if ($rowStorage && $tag === 'img' && $key === 'src' && $value === '{{logoUrl}}') {
                    self::require($raw === '{{logoUrl}}', 'encoded logo variable is unsupported');
                    continue;
                }
                self::require(!str_contains($value, '{{') && !str_contains($value, '}}'), 'email variables prohibited in attributes');
                self::attribute($tag, $key, $value, $hosts);
            }
        }
    }

    private static function attribute(string $tag, string $key, string $value, array $hosts): void
    {
        switch ($key) {
            case 'style': self::validateStyle($value); return;
            case 'alt': case 'title': return;
            case 'width': case 'height':
                self::require(preg_match('/\A[0-9]{1,4}\z/', $value) === 1, 'invalid image size'); return;
            case 'role':
                self::require($tag === 'table' && $value === 'presentation', 'unsupported email role'); return;
            case 'cellpadding': case 'cellspacing': case 'border':
                self::require($tag === 'table' && preg_match('/\A[0-9]{1,2}\z/', $value) === 1, 'invalid email table spacing'); return;
            case 'align':
                self::require(in_array($tag, ['table', 'td'], true) && in_array($value, ['left', 'center', 'right'], true), 'invalid email alignment'); return;
            case 'href': case 'src':
                self::require(($key === 'href' && $tag === 'a') || ($key === 'src' && $tag === 'img'), 'invalid resource attribute');
                self::resource($value, $hosts); return;
            default: throw new InvalidArgumentException('unsupported email HTML attribute: ' . $key);
        }
    }

    private static function resource(string $url, array $hosts): void
    {
        self::utf8($url);
        self::require(preg_match('/[\p{Cc}\p{Z}\s\\\\]/u', $url) !== 1 && preg_match('/%(?![0-9a-fA-F]{2})/', $url) !== 1, 'invalid email resource URL');
        $parts = parse_url($url);
        self::require(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']), 'HTTPS resource without credentials or port required');
        // 拒绝显式空端口和百分号主机；只接收ASCII域名、IP或已转换的punycode。
        self::require(preg_match('~\Ahttps://([^/?#]+)~', $url, $authority) === 1 && !str_ends_with($authority[1], ':'), 'invalid resource authority');
        $host = $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            self::require(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false, 'invalid IPv6 host');
        } else {
            self::require(preg_match('/\A[A-Za-z0-9.-]+\z/', $host) === 1, 'use ASCII or punycode resource host');
        }
        foreach ($hosts as $allowed) {
            if (is_string($allowed) && strcasecmp($host, $allowed) === 0) {
                return;
            }
        }
        throw new InvalidArgumentException('email resource host not approved');
    }

    private static function styleValue(string $key, string $value): bool
    {
        $enum = [
            'font-family' => ['Arial, Helvetica, sans-serif', 'Courier New, monospace'],
            'font-weight' => ['400', '500', '600', '700'], 'text-align' => ['left', 'center', 'right'],
            'vertical-align' => ['top', 'middle', 'bottom'], 'text-decoration' => ['none', 'underline'],
            'display' => ['block', 'inline-block'], 'border-collapse' => ['collapse', 'separate'],
            'table-layout' => ['fixed'], 'border-spacing' => ['0'],
        ];
        if (isset($enum[$key])) { return in_array($value, $enum[$key], true); }
        if (in_array($key, ['color', 'background-color'], true)) { return self::color($value) || $value === 'transparent'; }
        $ranges = ['font-size' => [8, 64], 'line-height' => [10, 96], 'height' => [1, 256], 'letter-spacing' => [0, 8], 'border-radius' => [0, 32]];
        if (isset($ranges[$key])) { return self::pixels($value, ...$ranges[$key]); }
        if (in_array($key, ['width', 'max-width'], true)) { return $value === '100%' || self::pixels($value, 1, 1024); }
        if (in_array($key, ['padding', 'margin'], true)) {
            $fields = preg_split('/[\s\p{Z}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            if (count($fields) < 1 || count($fields) > 4) { return false; }
            foreach ($fields as $field) { if (!self::pixels($field, 0, 64)) { return false; } }
            return true;
        }
        if (preg_match('/\A(?:padding|margin)-(?:top|right|bottom|left)\z/', $key) === 1) { return self::pixels($value, 0, 64); }
        if (preg_match('/\Aborder(?:-(?:top|right|bottom|left))?\z/', $key) === 1) {
            $fields = preg_split('/[\s\p{Z}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            return count($fields) === 3 && self::pixels($fields[0], 0, 4) && $fields[1] === 'solid' && self::color($fields[2]);
        }
        return false;
    }

    private static function pixels(string $value, int $min, int $max): bool
    {
        if ($value === '0') { return $min === 0; }
        return preg_match('/\A([0-9]{1,4})px\z/', $value, $m) === 1 && (int)$m[1] >= $min && (int)$m[1] <= $max;
    }
    private static function color(string $value): bool { return preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $value) === 1; }
    private static function decode(string $value): string { return html_entity_decode(str_replace(["\r\n", "\r"], "\n", $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    private static function trimSpace(string $value): string { return preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value); }
    private static function utf8(string $value): void { self::require(preg_match('//u', $value) === 1, 'invalid UTF-8'); }
    private static function require(bool $condition, string $message): void { if (!$condition) { throw new InvalidArgumentException($message); } }
}
