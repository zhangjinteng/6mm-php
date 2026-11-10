<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantSeo;

/** Storage groups copy by page; the current editor and theme snapshot consume homepage locales. */
final class SeoTranslations
{
    public static function home(?string $json): array
    {
        $document = json_decode($json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document)) throw new \UnexpectedValueException('Invalid SEO translations');
        $home = array_key_exists('homeByLocale', $document) ? $document['homeByLocale'] : $document;
        if (!is_array($home)) throw new \UnexpectedValueException('Invalid homepage SEO translations');
        return $home;
    }

    public static function withHome(?string $json, array $home): string
    {
        $stored = json_decode($json ?: '{}', false, 512, JSON_THROW_ON_ERROR);
        if (!$stored instanceof \stdClass) throw new \UnexpectedValueException('Invalid SEO translations');
        // Preserve other page sections, including empty JSON objects, when editing the homepage.
        $document = property_exists($stored, 'homeByLocale') ? $stored : new \stdClass();
        $document->homeByLocale = (object) $home;
        return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
