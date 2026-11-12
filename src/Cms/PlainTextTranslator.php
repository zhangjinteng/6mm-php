<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Throwable;

/** Plain-text preview only. Hosts retain credential selection, permissions and persistence. */
final class PlainTextTranslator
{
    public function __construct(private GoogleTranslationClient $google) {}

    public function fields(array $fields, array $limits, string $from, string $to, int $timeout, int $attempts = 1): array
    {
        $values = $errors = [];
        foreach ($fields as $field => $source) {
            if (!isset($limits[$field]) || !is_string($source) || trim($source) === '') {
                throw new \InvalidArgumentException('Invalid translation field.');
            }
            try {
                for ($attempt = 0; ; $attempt++) {
                    try { $value = trim($this->google->translate($source, $from, $to, false, $timeout)); break; }
                    catch (Throwable $e) {
                        // Retry transient provider/network failures, not invalid credentials or input.
                        if ($attempt + 1 >= max(1, min(10, $attempts))
                            || !preg_match('/^GOOGLE_(CONNECTION_FAILED|HTTP_(429|5[0-9]{2}))$/D', $e->getMessage())) throw $e;
                    }
                }
                if ($value === '' || mb_strlen($value) > $limits[$field]) {
                    $errors[$field] = '译文为空或超出字段长度限制，请手动调整';
                } else {
                    $values[$field] = $value;
                }
            } catch (Throwable) {
                $errors[$field] = 'Google 翻译失败，请重试或检查服务配置';
            }
        }
        return ['values' => $values, 'errors' => $errors];
    }

    public function many(#[\SensitiveParameter] string $key, string $text, string $from, array $targets, int $concurrency = 3, int $timeout = 20, int $attempts = 1): array
    {
        $result = ['translations' => [], 'failures' => []];
        $pending = $targets;
        for ($attempt = 0; $attempt < max(1, min(10, $attempts)) && $pending; $attempt++) {
            $batch = $this->google->withApiKey($key)->translateMany($text, $from, $pending, $timeout, $concurrency);
            $result['translations'] += $batch['translations'];
            $result['failures'] = $batch['failures'];
            $pending = array_intersect_key($pending, $batch['failures']);
        }
        foreach ($result['translations'] as $locale => $value) {
            if (trim($value) === '' || grapheme_strlen($value) > 100) {
                unset($result['translations'][$locale]);
                $result['failures'][$locale] = '译文为空或超出字段长度限制，请手动调整';
            }
        }
        return $result;
    }
}
