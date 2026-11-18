<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

/** Validate source tokens: a repairing DOM parser can hide rejected tags/attributes. */
final class EmailHtmlRules
{
    private const TAGS = ['p', 'br', 'div', 'span', 'strong', 'b', 'em', 'i', 'table', 'tbody', 'tr', 'td', 'a', 'img'];

    public static function validate(string $html, array $hosts): void
    {
        if (substr_count(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '{{logoUrl}}') > substr_count($html, '{{logoUrl}}')) {
            throw new EmailConfigException('邮件 Logo 变量不能使用 HTML 实体编码');
        }
        $offset = 0;
        while (($start = strpos($html, '<', $offset)) !== false) {
            $next = $html[$start + 1] ?? '';
            if ($next === '!' || $next === '?') self::tagError(); // comments, DOCTYPE and processing instructions
            if (!preg_match('~\G<(/?)([a-zA-Z][a-zA-Z0-9:-]*)(?=[\s/>])((?:[^\'"<>]|"[^"]*"|\'[^\']*\')*)>~s', $html, $m, 0, $start)) {
                if (preg_match('/[a-zA-Z\/]/', $next)) self::tagError();
                $offset = $start + 1;
                continue; // literal less-than text, e.g. "1 < 2"
            }
            $tag = strtolower($m[2]);
            if (!in_array($tag, self::TAGS, true)) self::tagError($tag);
            $attributes = $m[3];
            if ($m[1] === '/') {
                if (trim($attributes) !== '') self::tagError($tag);
            } else {
                self::attributes($tag, $attributes, $hosts);
            }
            $offset = $start + strlen($m[0]);
        }
        // logoUrl must be a literal complete img src, never encoded or interpolated
        // elsewhere. Attributes above validate the original and decoded values.
        $withoutLogo = preg_replace('~<img(?=[\s/>])(?:[^\'"<>]|"[^"]*"|\'[^\']*\')*>~i', '', $html);
        if (str_contains($withoutLogo, '{{logoUrl}}')) throw new EmailConfigException('Logo 变量只能作为 img 的完整 src');
    }

    private static function attributes(string $tag, string $source, array $hosts): void
    {
        $offset = 0;
        while ($offset < strlen($source)) {
            if (trim(substr($source, $offset)) === '' || trim(substr($source, $offset)) === '/') return;
            if (!preg_match('~\G\s+([^\s/\'"=<>`]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\'"=<>`]+)))?~', $source, $m, PREG_UNMATCHED_AS_NULL, $offset)) {
                throw new EmailConfigException('邮件 HTML 属性格式无效');
            }
            $name = strtolower($m[1]);
            $raw = $m[2] ?? $m[3] ?? $m[4] ?? '';
            $value = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($name === 'style') {
                EmailStyleRules::validate($value);
            } elseif (in_array($name, ['alt', 'title'], true)) {
                if (str_contains($value, '{{') || str_contains($value, '}}')) self::attributeError($name);
            } elseif (in_array($name, ['width', 'height'], true)) {
                if (!preg_match('/^[0-9]{1,4}$/D', $value)) self::attributeError($name);
            } elseif ($name === 'role' && $tag === 'table') {
                if ($value !== 'presentation') self::attributeError($name);
            } elseif (in_array($name, ['cellpadding', 'cellspacing', 'border'], true) && $tag === 'table') {
                if (!preg_match('/^[0-9]{1,2}$/D', $value)) self::attributeError($name);
            } elseif ($name === 'align' && in_array($tag, ['table', 'td'], true)) {
                if (!in_array($value, ['left', 'center', 'right'], true)) self::attributeError($name);
            } elseif (($name === 'href' && $tag === 'a') || ($name === 'src' && $tag === 'img')) {
                if ($name === 'src' && $raw === '{{logoUrl}}') {
                    // The publisher separately checks the site's actual logo URL.
                } else {
                    if (str_contains($value, '{{') || str_contains($value, '}}')) throw new EmailConfigException('邮件资源或 Logo 变量格式无效');
                    if ($value === '') throw new EmailConfigException('邮件资源必须使用已允许主机的 HTTPS 地址');
                    EmailTemplateRules::url($value, $hosts);
                }
            } else {
                self::attributeError($name);
            }
            // No template variable is permitted in any other attribute, including
            // one whose name or value libxml would silently discard or normalize.
            if (str_contains($value, '{{logoUrl}}') && !($tag === 'img' && $name === 'src' && $raw === '{{logoUrl}}')) {
                throw new EmailConfigException('Logo 变量只能作为 img 的完整 src');
            }
            $offset += strlen($m[0]);
        }
    }

    private static function tagError(string $tag = ''): never
    {
        throw new EmailConfigException('邮件 HTML 包含不允许的标签或注释'.($tag !== '' ? '：<'.$tag.'>' : ''));
    }

    private static function attributeError(string $name): never
    {
        throw new EmailConfigException('邮件 HTML 包含不允许的属性或属性值：'.$name);
    }
}
