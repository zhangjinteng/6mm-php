<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Caller must resolve/authorize the space; no tenant fallback is performed here. */
final class TranslationRuntime
{
    public static function forSpace(ConnectionInterface $db, object $space): array
    {
        if (!$space->is_enabled) throw new RuntimeException('TRANSLATION_SPACE_DISABLED');
        $setting = $space->translation_setting_id
            ? $db->table('cms.translation_settings')->where('id', $space->translation_setting_id)->first() : null;
        if (!$setting || $setting->provider !== 'google' || $setting->api_version !== 'v2' || !$setting->is_enabled) {
            throw new RuntimeException('TRANSLATION_CONFIG_DISABLED');
        }
        $locales = json_decode((string) ($space->locales_json ?? 'null'), true);
        if (!is_array($locales)) {
            $locales = is_array($space->enabled_locales ?? null) ? $space->enabled_locales
                : json_decode((string) ($space->enabled_locales ?? 'null'), true);
            if (!is_array($locales)) $locales = str_getcsv(trim((string) ($space->enabled_locales ?? ''), '{}'));
        }
        return (new TranslationSettingsStore($db))->runtime($setting,
            array_values(array_diff($locales, [$space->source_locale ?? 'zh-CN'])));
    }
}
