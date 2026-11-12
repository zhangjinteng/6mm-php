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
        // Preview only; the SEO save endpoint remains the sole writer.
        try {
            $result = (new \SixMm\Shared\Cms\PlainTextTranslator($google))->fields(
                $input['fields'], ['title' => 200, 'description' => 1000], $from, $to,
                max(1, min(60, (int) $settings['request_timeout_seconds'])));
        } catch (\InvalidArgumentException) { $this->reject('翻译字段或原文不正确'); }
        return ['target_locale' => $to, 'values' => (object) $result['values'], 'errors' => (object) $result['errors']];
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['translation' => $message]);
    }
}
