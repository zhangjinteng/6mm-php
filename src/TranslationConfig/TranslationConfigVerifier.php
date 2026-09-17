<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

interface TranslationConfigVerifier
{
    public function verify(string $apiKey): TranslationVerificationResult;
}
