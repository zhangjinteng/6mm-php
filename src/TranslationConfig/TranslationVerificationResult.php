<?php

declare(strict_types=1);

namespace SixMm\Shared\TranslationConfig;

final class TranslationVerificationResult
{
    private function __construct(
        private bool $valid,
        private ?string $error
    ) {
    }

    public static function valid(): self
    {
        return new self(true, null);
    }

    public static function invalid(string $error): self
    {
        return new self(false, $error);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
