<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

trait HasCmsSourceScope
{
    private ?CmsSourceScope $cmsSourceScope = null;
    private ?object $cmsPublishingSpace = null;

    public function withCmsSourceScope(array $input, string $action, string $module): self
    {
        $this->cmsSourceScope = new CmsSourceScope($this->db(), $input, $action, $module);
        return $this;
    }

    /** Space ID is resolved by the host from the authenticated tenant. */
    public function withTenantSpace(int $spaceId, array $input, string $action, string $module): self
    {
        $input['space_id'] = $spaceId;
        unset($input['cms_source_type'], $input['cms_tenant_id']);
        $this->cmsSourceScope = new CmsSourceScope($this->db(), $input, $action, $module, [$spaceId]);
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
