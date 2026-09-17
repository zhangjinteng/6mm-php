<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

use RuntimeException;

final class TranslationConfigException extends RuntimeException
{
    public const API_KEY_REQUIRED = 'api_key_required';
    public const DECRYPT_FAILED = 'decrypt_failed';
    public const INVALID_PARAMETERS = 'invalid_parameters';

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
