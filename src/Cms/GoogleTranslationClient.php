<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GoogleTranslationClient
{
    private ?string $apiKey = null;

    /** Clone per request/job; never mutate process-global config in a long-lived worker. */
    public function withApiKey(#[\SensitiveParameter] string $key): static
    {
        $client = clone $this;
        $client->apiKey = $key;
        return $client;
    }

    private function key(): string
    {
        return $this->apiKey ?? (string) config('cms_translation.google_api_key');
    }

    public function configured(): bool
    {
        return trim($this->key()) !== '';
    }

    public function translate(string $source, string $from, string $to, bool $html, int $timeout = 30): string
    {
        if (!$this->configured()) {
            throw new RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        }
        // Keep requests within our service budget; do not split HTML at arbitrary offsets.
        if (strlen($source) > 90000) {
            throw new RuntimeException('SOURCE_TOO_LONG');
        }
        try {
            $response = Http::timeout($timeout)->withOptions(['allow_redirects' => false, 'connect_timeout' => 5])->withHeaders(['X-Goog-Api-Key' => $this->key()])->post('https://translation.googleapis.com/language/translate/v2', [
                'q' => [$source],
                'source' => TranslationCatalog::googleLocale($from),
                'target' => TranslationCatalog::googleLocale($to),
                'format' => $html ? 'html' : 'text',
            ]);
        } catch (Throwable $e) {
            // Never propagate request objects, URLs with credentials, or provider response bodies.
            throw new RuntimeException('GOOGLE_CONNECTION_FAILED');
        }
        if (!$response->successful()) {
            throw new RuntimeException('GOOGLE_HTTP_' . $response->status());
        }
        $text = $response->json('data.translations.0.translatedText');
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('GOOGLE_EMPTY_RESPONSE');
        }
        return $html ? $text : html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Bounded parallel plain-text requests, using the same credential/transport as CMS and SEO. */
    public function translateMany(string $source, string $from, array $targets, int $timeout = 20, int $concurrency = 3): array
    {
        if (!$this->configured()) throw new RuntimeException('GOOGLE_CREDENTIAL_MISSING');
        if (strlen($source) > 90000 || count($targets) > 20) throw new RuntimeException('SOURCE_TOO_LONG');
        $translations = $failures = [];
        foreach (array_chunk($targets, max(1, min(20, $concurrency)), true) as $batch) {
            $pool = new \Illuminate\Http\Client\Pool(Http::getFacadeRoot());
            $pending = $responses = [];
            foreach ($batch as $locale => $to) {
                try {
                    $pending[$locale] = $pool->as($locale)->timeout(max(1, min(120, $timeout)))
                        ->withOptions(['allow_redirects' => false, 'connect_timeout' => 5])
                        ->withHeaders(['X-Goog-Api-Key' => $this->key()])
                        ->post('https://translation.googleapis.com/language/translate/v2', [
                            'q' => [$source], 'source' => TranslationCatalog::googleLocale($from),
                            'target' => TranslationCatalog::googleLocale($to), 'format' => 'text',
                        ])->then(static fn ($response) => $response, static fn () => null);
                } catch (Throwable) { $responses[$locale] = null; }
            }
            foreach ($pending as $locale => $promise) {
                try { $responses[$locale] = $promise->wait(); }
                catch (Throwable) { $responses[$locale] = null; }
            }
            foreach ($batch as $locale => $_) {
                $response = $responses[$locale] ?? null;
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()
                    && is_string($text = $response->json('data.translations.0.translatedText')) && trim($text) !== '') {
                    $translations[$locale] = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                } else {
                    $failures[$locale] = 'Google 翻译失败，请重试或检查服务配置';
                }
            }
        }
        return compact('translations', 'failures');
    }
}
