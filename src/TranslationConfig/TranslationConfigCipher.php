<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

interface TranslationConfigCipher
{
    public function encrypt(string $value): string;

    public function decrypt(string $value): string;
}
