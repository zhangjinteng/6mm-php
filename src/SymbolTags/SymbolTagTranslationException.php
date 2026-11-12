<?php

namespace SixMm\Shared\SymbolTags;

use RuntimeException;

class SymbolTagTranslationException extends RuntimeException
{
    public const INVALID_PARAMETERS = 'translation_invalid_parameters';
    public const NOT_FOUND = 'not_found';
    public const API_KEY_REQUIRED = 'translation_api_key_required';
    public const CONFIG_DISABLED = 'translation_config_disabled';
    public const DECRYPT_FAILED = 'translation_decrypt_failed';

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
