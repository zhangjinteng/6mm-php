<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use Illuminate\Validation\Rule;

/** Same settings contract for CMS, SEO and symbol tags; scope is resolved by the host. */
final class TranslationSettingsInput
{
    public static function rules(bool $test = false): array
    {
        $rules = ['api_key' => 'nullable|string|max:512|regex:/^[A-Za-z0-9_-]+$/D',
            'space_revision' => 'required|integer|min:0'];
        return $test ? $rules : $rules + [
            'is_enabled' => 'required|boolean',
            'target_locales' => 'present|array|max:19',
            'target_locales.*' => ['required', 'string', 'distinct', Rule::in(array_keys(TranslationCatalog::LOCALES))],
            'concurrency_limit' => 'required|integer|between:1,20',
            'request_timeout_seconds' => 'required|integer|between:1,120',
            'max_attempts' => 'required|integer|between:1,10',
        ];
    }
}
