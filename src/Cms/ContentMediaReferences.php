<?php

declare(strict_types=1);

namespace SixMm\Shared\Cms;

use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\Media\MediaRegistry;

final class ContentMediaReferences
{
    /** Use the saved, sanitized HTML, never a client-supplied list of uploaded files. */
    public static function sync(array $record, string $module): void
    {
        $html = $record['body_html'] ?? '';
        $urls = [];
        if (stripos($html, '<img') !== false) {
            $doc = new DOMDocument();
            $doc->loadHTML('<meta charset="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($doc->getElementsByTagName('img') as $image) {
                $src = trim($image->getAttribute('src'));
                if ($src !== '') $urls[$src] = true;
            }
        }
        $ids = [];
        foreach (array_chunk(array_keys($urls), 500) as $chunk) {
            $registered = DB::connection('pgsql')->table('cms.media_files')
                ->where('imgbed_instance', config('imgbed.instance'))->whereIn('public_url', $chunk)
                ->pluck('id', 'public_url');
            $prefix = rtrim(config('imgbed.public_base'), '/').'/media/upload_files/';
            foreach ($chunk as $url) {
                if ($registered->has($url)) $ids[] = $registered->get($url);
                elseif (str_starts_with($url, $prefix)) {
                    throw ValidationException::withMessages(['body_html' => '正文图片尚未登记，请重新上传']);
                }
            }
        }
        $tenant = $record['cms_tenant_id'] ?? null;
        $entity = $module === 'academy' ? 'course' : $module;
        $module = 'cms.'.$module;
        // A record can be edited in either host. Keep its existing canonical application key.
        $registry = new MediaRegistry();
        $registry->checkMediaReferences('platform_admin', $tenant ? 'white_label' : 'platform',
            (int) $tenant, $module, $entity, (string) $record['id'], 'cover', $record['publish_status'],
            $registry->idsForUrl($record['cover_url'] ?? null, 'cover_url'));
        $registry->checkMediaReferences('platform_admin', $tenant ? 'white_label' : 'platform',
            (int) $tenant, $module, $entity, (string) $record['id'], 'content', $record['publish_status'], $ids);
    }
}
