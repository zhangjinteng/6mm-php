<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Validation\ValidationException;

class TranslationCatalog
{
    public const LOCALES = [
        'zh-CN' => ['简体中文', '简体中文', '🇨🇳'],
        'en' => ['英语', 'English', '🇺🇸'],
        'ja' => ['日语', '日本語', '🇯🇵'],
        'ru' => ['俄语', 'Русский', '🇷🇺'],
        'es-419' => ['西班牙语（拉美）', 'Español (Latinoamérica)', '🇲🇽'],
        'it' => ['意大利语', 'Italiano', '🇮🇹'],
        'fr' => ['法语', 'Français', '🇫🇷'],
        'de' => ['德语', 'Deutsch', '🇩🇪'],
        'zh-TW' => ['繁体中文', '繁體中文', '🇭🇰'],
        'pt-BR' => ['葡萄牙语（巴西）', 'Português (Brasil)', '🇧🇷'],
        'id' => ['印尼语', 'Bahasa Indonesia', '🇮🇩'],
        'pl' => ['波兰语', 'Polski', '🇵🇱'],
        'vi' => ['越南语', 'Tiếng Việt', '🇻🇳'],
        'uk' => ['乌克兰语', 'Українська', '🇺🇦'],
        'pt-PT' => ['葡萄牙语（葡萄牙）', 'Português (Portugal)', '🇵🇹'],
        'es' => ['西班牙语（国际）', 'Español', '🇪🇸'],
        'uz' => ['乌兹别克语', 'Oʻzbekcha', '🇺🇿'],
        'ar' => ['阿拉伯语', 'العربية', '🇸🇦'],
        'fil' => ['菲律宾语', 'Filipino', '🇵🇭'],
        'az' => ['阿塞拜疆语', 'Azərbaycan', '🇦🇿'],
    ];
    public const MODULES = [
        'news' => ['新闻', 'newspaper'],
        'academy' => ['学院', 'graduation-cap'],
        'help' => ['帮助中心', 'circle-help'],
        'announcement' => ['公告中心', 'megaphone'],
    ];
    public const FIELD_LABELS = [
        'name' => '分类名称',
        'title' => '标题',
        'summary' => '摘要',
        'body_html' => '正文',
        'seo_description' => 'SEO 内容',
        'search_keywords' => '搜索关键词',
    ];
    public const LIMITS = [
        'name' => 120,
        'title' => 255,
        'summary' => 10000,
        'body_html' => 1000000,
        'seo_description' => 2000,
        'search_keywords' => 2000,
    ];

    public static function spec(string $module, string $kind): array
    {
        if (!isset(self::MODULES[$module]) || !in_array($kind, ['content', 'category'], true)) {
            throw ValidationException::withMessages(['module' => '不支持的翻译对象']);
        }
        return $kind === 'category' ? [
            'table' => 'categories',
            'translations' => 'category_translations',
            'fk' => 'category_id',
            'text' => 'name',
            'fields' => ['name'],
        ] : [
            'table' => 'contents',
            'translations' => 'content_translations',
            'fk' => 'content_id',
            'text' => 'translated_text',
            'fields' => array_merge([
                'title',
                'summary',
                'body_html',
                'seo_description',
            ], $module === 'help' ? ['search_keywords'] : []),
        ];
    }

    public static function googleLocale(string $locale): string
    {
        return ['es-419' => 'es', 'fil' => 'tl'][$locale] ?? $locale;
    }

    public static function sourceHash(string $text): string
    {
        return hash('sha256', $text);
    }
}
