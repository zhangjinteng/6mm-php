<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use SixMm\Shared\Media\MediaRegistry;

trait HasCategoryImage
{
    private function categoryImage(array $result, int $id, string $module): array
    {
        $owner = (int) ($result['cms_tenant_id'] ?? 0);
        $image = $this->db()->table('cms.media_references as r')
            ->join('cms.media_files as f', 'f.id', '=', 'r.media_id')
            ->where(['r.app_key' => 'platform_admin', 'r.module_key' => $module,
                'r.entity_type' => 'category', 'r.entity_id' => (string) $id, 'r.field_key' => 'image',
                'r.usage_state' => 'published', 'r.owner_type' => $owner ? 'white_label' : 'platform',
                'r.owner_id' => $owner, 'f.storage_status' => 'available'])
            ->whereNull('r.released_at')->first(['r.media_id', 'f.public_url']);
        $result['image_media_id'] = $image ? (string) $image->media_id : null;
        $result['image_url'] = $image->public_url ?? null;
        return $result;
    }

    private function syncCategoryImage(object $space, int $id, array $ids, string $module): void
    {
        $source = $this->cmsSourceRow(['space_id' => $space->id]);
        $owner = (int) ($source['cms_tenant_id'] ?? 0);
        (new MediaRegistry())->checkMediaReferences('platform_admin', $owner ? 'white_label' : 'platform',
            $owner, $module, 'category', (string) $id, 'image', 'published', $ids);
    }
}
