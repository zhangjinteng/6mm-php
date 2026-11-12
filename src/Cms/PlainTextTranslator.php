<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Throwable;

/** Plain-text preview only. Hosts retain credential selection, permissions and persistence. */
final class PlainTextTranslator
{
    public function __construct(private GoogleTranslationClient $google) {}

    public function fields(array $fields, array $limits, string $from, string $to, int $timeout): array
    {
        $values = $errors = [];
        foreach ($fields as $field => $source) {
            if (!isset($limits[$field]) || !is_string($source) || trim($source) === '') {
                throw new \InvalidArgumentException('Invalid translation field.');
            }
            try {
                $value = trim($this->google->translate($source, $from, $to, false, $timeout));
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

    public function many(#[\SensitiveParameter] string $key, string $text, string $from, array $targets, int $concurrency = 3, int $timeout = 20): array
    {
        $result = $this->google->withApiKey($key)->translateMany($text, $from, $targets, $timeout, $concurrency);
        foreach ($result['translations'] as $locale => $value) {
            if (trim($value) === '' || grapheme_strlen($value) > 100) {
                unset($result['translations'][$locale]);
                $result['failures'][$locale] = '译文为空或超出字段长度限制，请手动调整';
            }
        }
        return $result;
    }
}
