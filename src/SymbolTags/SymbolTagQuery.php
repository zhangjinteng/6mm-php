<?php

declare(strict_types=1);

namespace SixMm\Shared\SymbolTags;

final class SymbolTagQuery
{
    public function __construct(
        private string $keyword = '',
        private ?int $parentId = null,
        private ?int $enabled = null
    ) {
        $this->keyword = trim($this->keyword);
        $this->parentId = $this->parentId !== null && $this->parentId >= 0
            ? $this->parentId
            : null;
        $this->enabled = in_array($this->enabled, [0, 1], true)
            ? $this->enabled
            : null;
    }

    public function keyword(): string
    {
        return $this->keyword;
    }

    public function parentId(): ?int
    {
        return $this->parentId;
    }

    public function enabled(): ?int
    {
        return $this->enabled;
    }
}
