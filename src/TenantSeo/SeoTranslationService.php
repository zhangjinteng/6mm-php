<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantSeo;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\Cms\TranslationCredential;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\Cms\GoogleTranslationClient;
use SixMm\Shared\Cms\TranslationCatalog;
use Throwable;

final class SeoTranslationService
{
    private \Closure $settings;
    /** Settings loader is host-owned: Platform default space vs Agent's exact site space. */
    public function __construct(private SeoConfigStore $store, private ConnectionInterface $db, callable $settings, private GoogleTranslationClient $google)
    {
        $this->settings = \Closure::fromCallable($settings);
    }

    public function translationContext(array $input): array
    {
        $this->store->detail($input);
        $space = $this->db->table('cms.spaces')->where('space_type', 'site')->where('home_site_id', $input['site_id'])->first();
        if (!$space) return ['available' => false, 'reason' => '当前站点尚未配置内容翻译空间', 'settings' => null];
        return ['available' => (bool) $space->is_enabled, 'reason' => $space->is_enabled ? null : '当前站点内容空间未启用',
            'source_locale' => $space->source_locale, 'settings' => ($this->settings)((int) $space->id)];
    }

    public function translate(array $input): array
    {
        $input = \Illuminate\Support\Facades\Validator::make($input, SeoConfigStore::rules('translate'))->validate();
        $context = $this->translationContext($input);
        $settings = $context['settings'];
        if (!$context['available'] || !$settings || !$settings['supported'] || !$settings['is_enabled'] || !$settings['credential_configured']) {
            $this->reject($context['reason'] ?? '平台未启用 Google 翻译，请检查翻译配置');
        }
        $from = $input['source_locale'];
        $to = $input['target_locale'];
        $enabled = array_merge([$context['source_locale']], $settings['target_locales']);
        if (!isset(TranslationCatalog::LOCALES[$from], TranslationCatalog::LOCALES[$to]) || $from === $to || !in_array($to, $enabled, true)) {
            $this->reject('源语言或目标语言不正确，目标语言需要在平台翻译配置中启用');
        }
        // Resolve only server-selected settings. A bad encrypted key never falls back to an environment key.
        $configuration = $this->db->table('cms.spaces')->where('id', $settings['space_id'])->first();
        $setting = ($configuration->translation_setting_id ?? null)
            ? $this->db->table('cms.translation_settings')->where('id', $configuration->translation_setting_id)->first() : null;
        try { $google = $this->google->withApiKey((new TranslationCredential())->resolve($setting)); }
        catch (Throwable) { $this->reject('翻译密钥不可用，请联系管理员检查翻译配置'); }
        $values = $errors = [];
        // Preview only: the SEO save endpoint remains the sole writer of translated copy.
        foreach ($input['fields'] as $field => $source) {
            if (!in_array($field, ['title', 'description'], true) || trim($source) === '') $this->reject('翻译字段或原文不正确');
            try {
                $value = trim($google->translate($source, $from, $to, false, max(1, min(60, (int) $settings['request_timeout_seconds']))));
                if ($value === '' || mb_strlen($value) > ($field === 'title' ? 200 : 1000)) {
                    $errors[$field] = '译文为空或超出字段长度限制，请手动调整';
                } else {
                    $values[$field] = $value;
                }
            } catch (Throwable $e) {
                $errors[$field] = 'Google 翻译失败，请重试或检查服务配置';
            }
        }
        return ['target_locale' => $to, 'values' => (object) $values, 'errors' => (object) $errors];
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['translation' => $message]);
    }
}
