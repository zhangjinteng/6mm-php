<?php

namespace SixMm\Shared\SymbolTags;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class SymbolTagTranslations
{
    private const LANGUAGES = [
        'zh-CN' => ['label' => '简体中文', 'label_en' => 'Simplified Chinese', 'google' => 'zh-CN'],
        'en' => ['label' => '英语', 'label_en' => 'English', 'google' => 'en'],
        'ja' => ['label' => '日语', 'label_en' => 'Japanese', 'google' => 'ja'],
        'ru' => ['label' => '俄语', 'label_en' => 'Russian', 'google' => 'ru'],
        'es-419' => ['label' => '西班牙语（拉美）', 'label_en' => 'Spanish (Latin America)', 'google' => 'es'],
        'it' => ['label' => '意大利语', 'label_en' => 'Italian', 'google' => 'it'],
        'fr' => ['label' => '法语', 'label_en' => 'French', 'google' => 'fr'],
        'de' => ['label' => '德语', 'label_en' => 'German', 'google' => 'de'],
        'zh-TW' => ['label' => '繁体中文', 'label_en' => 'Traditional Chinese', 'google' => 'zh-TW'],
        'pt-BR' => ['label' => '葡萄牙语（巴西）', 'label_en' => 'Portuguese (Brazil)', 'google' => 'pt'],
        'id' => ['label' => '印尼语', 'label_en' => 'Indonesian', 'google' => 'id'],
        'pl' => ['label' => '波兰语', 'label_en' => 'Polish', 'google' => 'pl'],
        'vi' => ['label' => '越南语', 'label_en' => 'Vietnamese', 'google' => 'vi'],
        'uk' => ['label' => '乌克兰语', 'label_en' => 'Ukrainian', 'google' => 'uk'],
        'pt-PT' => ['label' => '葡萄牙语（葡萄牙）', 'label_en' => 'Portuguese (Portugal)', 'google' => 'pt'],
        'es' => ['label' => '西班牙语（国际）', 'label_en' => 'Spanish (International)', 'google' => 'es'],
        'uz' => ['label' => '乌兹别克语', 'label_en' => 'Uzbek', 'google' => 'uz'],
        'ar' => ['label' => '阿拉伯语', 'label_en' => 'Arabic', 'google' => 'ar'],
        'fil' => ['label' => '菲律宾语', 'label_en' => 'Filipino', 'google' => 'tl'],
        'az' => ['label' => '阿塞拜疆语', 'label_en' => 'Azerbaijani', 'google' => 'az'],
    ];

    private ?array $symbolScope = null;
    private \Closure $runtime;
    private \Closure $translateMany;

    /** Host derives credentials and ownership; shared code owns validation/storage. */
    public function __construct(private ConnectionInterface $connection, callable $runtime, ?callable $translateMany = null)
    {
        $this->runtime = \Closure::fromCallable($runtime);
        $this->translateMany = \Closure::fromCallable($translateMany ?? static function (string $key, string $text, string $from, array $targets, int $concurrency, int $timeout = 30, int $attempts = 3): array {
            return (new \SixMm\Shared\Cms\PlainTextTranslator(new \SixMm\Shared\Cms\GoogleTranslationClient()))
                ->many($key, $text, $from, $targets, $concurrency, $timeout, $attempts);
        });
    }

    public function forScope(int $siteId, int $agentId): self
    {
        if ($siteId < 0 || $agentId < 0 || ($siteId === 0 && $agentId > 0)) {
            throw $this->exception(SymbolTagTranslationException::INVALID_PARAMETERS);
        }
        $clone = clone $this;
        $clone->symbolScope = [$siteId, $agentId];
        return $clone;
    }

    private function translations(int $tagId)
    {
        return $this->connection->table('symbol_tag_translation')->where($this->translationIdentity($tagId));
    }

    private function translationIdentity(int $tagId): array
    {
        return $this->symbolScope !== null
            ? ['symbol_tag_id'=>$tagId, 'agent_symbol_tag_id'=>0]
            : ['symbol_tag_id'=>0, 'agent_symbol_tag_id'=>$tagId];
    }

    private function tagQuery(int $agentId)
    {
        $query = $this->connection->table($this->symbolScope !== null ? 'symbol_tag' : 'agent_symbol_tag')
            ->where('agent_id', $agentId);
        if ($this->symbolScope !== null) {
            $query->where('home_site_id', $this->symbolScope[0])->where('agent_id', $this->symbolScope[1]);
        }
        return $query;
    }

    public function detail(int $agentId, int $tagId): array
    {
        if (($agentId <= 0 && $this->symbolScope === null) || $tagId < 0) {
            throw $this->exception(
                SymbolTagTranslationException::INVALID_PARAMETERS
            );
        }
        if ($tagId === 0) {
            return $this->formatDetail(0, '', []);
        }

        $tag = $this->tag($agentId, $tagId);
        $stored = $this->translations($tagId)
            ->pluck('name', 'locale')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        if (trim((string) ($stored['zh-CN'] ?? '')) === '') {
            $stored['zh-CN'] = trim((string) ($tag->tag_name_zh ?? $tag->tag_name ?? ''));
        }
        if (trim((string) ($stored['en'] ?? '')) === '') {
            $stored['en'] = trim((string) ($tag->tag_name_en ?? ''));
        }

        return $this->formatDetail($tagId, (string) $tag->tag_name, $stored);
    }

    public function save(int $agentId, int $tagId, array $translations): array
    {
        $normalized = $this->normalizeTranslations($translations);
        $now = $this->timestamp();

        $this->connection->transaction(function () use ($agentId, $tagId, $normalized, $now): void {
            $this->tag($agentId, $tagId, true);
            foreach (self::LANGUAGES as $locale => $_definition) {
                $value = $normalized[$locale] ?? '';
                if ($value === '') {
                    $this->translations($tagId)
                        ->where('locale', $locale)
                        ->delete();
                    continue;
                }

                $translation = $this->translations($tagId)
                    ->where('locale', $locale);
                if ($translation->exists()) {
                    $translation->update(['name' => $value, 'updated_at' => $now]);
                } else {
                    $this->connection->table('symbol_tag_translation')->insert([
                        ...$this->translationIdentity($tagId),
                        'locale' => $locale,
                        'name' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $tagUpdates = ['updated_at' => $now];
            if (($normalized['zh-CN'] ?? '') !== '') {
                $tagUpdates['tag_name'] = $normalized['zh-CN'];
                $tagUpdates['tag_name_zh'] = $normalized['zh-CN'];
            }
            if (array_key_exists('en', $normalized)) {
                $tagUpdates['tag_name_en'] = $normalized['en'];
            }
            $this->tagQuery($agentId)
                ->where('id', $tagId)
                ->update($tagUpdates);
        });

        return $this->detail($agentId, $tagId);
    }

    public function translate(
        int $agentId,
        int $tagId,
        string $sourceLocale,
        string $sourceText,
        array $targetLocales
    ): array {
        if (($agentId <= 0 && $this->symbolScope === null) || $tagId < 0) {
            throw $this->exception(
                SymbolTagTranslationException::INVALID_PARAMETERS
            );
        }
        if ($tagId > 0) {
            $this->tag($agentId, $tagId);
        }
        $sourceText = trim($sourceText);
        if (!isset(self::LANGUAGES[$sourceLocale]) || $sourceText === ''
            || $this->textLength($sourceText) > 100) {
            throw $this->exception(
                SymbolTagTranslationException::INVALID_PARAMETERS
            );
        }

        $targets = [];
        foreach (array_values(array_unique($targetLocales)) as $locale) {
            $locale = (string) $locale;
            if ($locale === $sourceLocale || !isset(self::LANGUAGES[$locale])) {
                continue;
            }
            $targets[$locale] = self::LANGUAGES[$locale]['google'];
        }
        if ($targets === []) {
            throw $this->exception(
                SymbolTagTranslationException::INVALID_PARAMETERS
            );
        }

        $runtime = ($this->runtime)($agentId, $this->symbolScope);
        if (isset($runtime['target_locales']) && array_diff(array_keys($targets), $runtime['target_locales'])) {
            throw $this->exception(SymbolTagTranslationException::INVALID_PARAMETERS);
        }

        return ($this->translateMany)(
            $runtime['api_key'],
            $sourceText,
            self::LANGUAGES[$sourceLocale]['google'],
            $targets,
            $runtime['concurrency'],
            $runtime['timeout'] ?? 30,
            $runtime['max_attempts'] ?? 1
        );
    }

    private function normalizeTranslations(array $translations): array
    {
        if (array_diff(array_keys($translations), array_keys(self::LANGUAGES))) {
            throw $this->exception(SymbolTagTranslationException::INVALID_PARAMETERS);
        }
        $normalized = [];
        foreach (self::LANGUAGES as $locale => $_definition) {
            if (isset($translations[$locale]) && !is_string($translations[$locale])) {
                throw $this->exception(SymbolTagTranslationException::INVALID_PARAMETERS);
            }
            $value = trim((string) ($translations[$locale] ?? ''));
            if ($this->textLength($value) > 100) {
                throw $this->exception(
                    SymbolTagTranslationException::INVALID_PARAMETERS
                );
            }
            $normalized[$locale] = $value;
        }

        return $normalized;
    }

    private function formatDetail(int $tagId, string $tagName, array $stored): array
    {
        $languages = [];
        $configured = 0;
        foreach (self::LANGUAGES as $locale => $definition) {
            $value = trim((string) ($stored[$locale] ?? ''));
            if ($value !== '') {
                $configured++;
            }
            $languages[] = [
                'locale' => $locale,
                'label' => $definition['label'],
                'label_en' => $definition['label_en'],
                'value' => $value,
            ];
        }

        return [
            'tag_id' => $tagId,
            'tag_name' => $tagName,
            'configured_count' => $configured,
            'total' => count(self::LANGUAGES),
            'languages' => $languages,
        ];
    }

    private function tag(int $agentId, int $tagId, bool $lock = false): object
    {
        if (($agentId <= 0 && $this->symbolScope === null) || $tagId <= 0) {
            throw $this->exception(
                SymbolTagTranslationException::INVALID_PARAMETERS
            );
        }
        $tag = $this->tagQuery($agentId)
            ->where('id', $tagId)
            ->whereNull('deleted_at')
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->first();
        if ($tag === null) {
            throw $this->exception(
                SymbolTagTranslationException::NOT_FOUND
            );
        }

        return $tag;
    }

    private function textLength(string $value): int
    {
        $length = grapheme_strlen($value);

        return $length === false ? strlen($value) : $length;
    }

    protected function exception(string $reason): \RuntimeException
    {
        return SymbolTagTranslationException::because($reason);
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
