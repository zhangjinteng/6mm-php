<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

trait HasCmsSourceScope
{
    private ?CmsSourceScope $cmsSourceScope = null;

    public function withCmsSourceScope(array $input, string $action, string $module): self
    {
        $this->cmsSourceScope = new CmsSourceScope($this->db(), $input, $action, $module);
        return $this;
    }

    private function cmsSourceRow(array $row): array
    {
        return $this->cmsSourceScope?->metadata($row) ?? $row;
    }

    private function cmsSourceOptions(): array
    {
        return $this->cmsSourceScope?->options() ?? [];
    }
}
