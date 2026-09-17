<?php

declare(strict_types=1);

namespace SixMm\Shared\SymbolTags;

use RuntimeException;

final class SymbolTagException extends RuntimeException
{
    public const INVALID_PARAMETERS = 'invalid_parameters';
    public const NOT_FOUND = 'not_found';
    public const INVALID_PARENT = 'invalid_parent';
    public const PARENT_WITH_CHILDREN = 'parent_with_children';
    public const CODE_EXISTS = 'code_exists';
    public const HAS_CHILDREN = 'has_children';
    public const IN_USE = 'in_use';

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
