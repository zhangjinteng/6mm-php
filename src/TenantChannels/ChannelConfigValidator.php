<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantChannels;

final class ChannelConfigValidator
{
    public const SOCIAL_KEYS = ['x', 'telegram', 'youtube', 'tiktok', 'facebook', 'discord', 'instagram', 'medium', 'reddit', 'linkedin', 'whatsapp', 'github'];
    public const DOWNLOAD_KEYS = ['app_store', 'google_play', 'apk'];

    public static function section(array $links, string $field): array
    {
        $keys = match ($field) {
            'social_links' => self::SOCIAL_KEYS,
            'app_downloads' => self::DOWNLOAD_KEYS,
            default => throw new ChannelConfigException($field, '不支持的渠道配置字段'),
        };
        if (array_diff(array_keys($links), $keys) !== []) {
            throw new ChannelConfigException($field, '包含不支持的渠道平台');
        }
        $result = [];
        foreach ($keys as $key) {
            $value = $links[$key] ?? null;
            if ($value === null) continue;
            if (!is_string($value)) throw new ChannelConfigException($field . '.' . $key, '请输入有效的 HTTPS 链接（不含账号密码）');
            $url = trim($value);
            if ($url === '') continue;
            if (!self::validUrl($url)) throw new ChannelConfigException($field . '.' . $key, '请输入有效的 HTTPS 链接（不含账号密码）');
            $result[$key] = $url;
        }
        return $result;
    }

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        return mb_strlen($url) <= 2048 && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false && $parts
            && strtolower($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    /** Select supported display values while keeping future keys in persisted JSON. */
    public static function supported(array $links, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (isset($links[$key]) && is_string($links[$key]) && trim($links[$key]) !== '') $result[$key] = trim($links[$key]);
        }
        return $result;
    }
}
