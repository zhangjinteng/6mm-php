<?php

declare(strict_types=1);

namespace SixMm\Shared\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MediaRegistry
{
    private const FOLDER = 'media/upload_files';

    public function upload(UploadedFile $file, ?int $adminId, ?int $tenantId = null): array
    {
        if (!$file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => '请选择不超过 5 MB 的有效图片']);
        }
        $bytes = file_get_contents($file->getPathname());
        $mime = $file->getMimeType();
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if ($bytes === false || !$extension || !($size = getimagesizefromstring($bytes))) {
            throw ValidationException::withMessages(['file' => '无效的封面图片']);
        }
        if (max($size[0], $size[1]) > 8192) {
            throw ValidationException::withMessages(['file' => '图片宽高不能超过 8192 像素']);
        }
        $name = md5($bytes).'.'.$extension;
        $key = self::FOLDER.'/'.$name;
        $sha = hash('sha256', $bytes);
        $db = DB::connection('pgsql');
        $instance = config('imgbed.instance');
        $ownerType = $tenantId === null ? 'platform' : 'white_label';
        $ownerId = $tenantId ?? 0;
        if ($tenantId !== null && $tenantId < 1) throw new \InvalidArgumentException('Invalid tenant');
        return $db->transaction(function () use ($file, $adminId, $db, $instance, $bytes, $mime, $size, $key, $name, $sha, $ownerType, $ownerId): array {
            // Serialize only uploads targeting the same physical object, including initial registration.
            $db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$instance.':'.$key]);
            $existing = $db->table('cms.media_files')->where('imgbed_instance', $instance)
                ->where('file_id', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->storage_status !== 'available' || $existing->sha256 !== $sha) {
                    throw ValidationException::withMessages(['file' => '同名图片内容不一致或文件不可用']);
                }
                // Proof of identical bytes permits reuse, not transfer of ownership.
                if ($existing->owner_type !== 'platform' && ($existing->owner_type !== $ownerType || (int) $existing->owner_id !== $ownerId)) {
                    $db->table('cms.media_upload_grants')->insertOrIgnore(['media_id' => $existing->id,
                        'owner_type' => $ownerType, 'owner_id' => $ownerId]);
                }
                return ['media_id' => (string) $existing->id, 'url' => $existing->public_url];
            }

            $base = rtrim(config('imgbed.base_url'), '/');
            $url = rtrim(config('imgbed.public_base'), '/').'/'.$key;
            $token = config('imgbed.token');
            if (!$token || !str_starts_with($base, 'https://') || !str_starts_with($url, 'https://')) {
                throw ValidationException::withMessages(['file' => '请先配置服务端图床地址和凭据']);
            }
            $client = fn () => Http::withToken($token)->withoutRedirecting()->timeout(20);
            // A previous upload may have succeeded remotely before its DB transaction committed.
            $list = $client()->get($base.'/api/manage/list', [
                'dir' => self::FOLDER, 'search' => $name, 'recursive' => 'false',
                'start' => 0, 'count' => 100,
            ])->throw()->json();
            if (!is_array($list['files'] ?? null) || ($list['isIndexedResponse'] ?? false) !== true) {
                throw new RuntimeException('Image directory lookup unavailable');
            }
            $remote = collect($list['files'])->firstWhere('name', $key);
            if ($remote !== null) {
                $response = Http::withoutRedirecting()->timeout(15)->get($url);
                if (!$response->ok() || !hash_equals($sha, hash('sha256', $response->body()))) {
                    throw ValidationException::withMessages(['file' => '图床已有同名文件，内容校验未通过']);
                }
            } else {
                if (($list['directFileCount'] ?? count($list['files'])) > count($list['files'])) {
                    throw new RuntimeException('Image directory lookup incomplete');
                }
                $query = http_build_query(['uploadChannel' => 's3', 'channelName' => config('imgbed.channel'),
                    'uploadFolder' => self::FOLDER, 'uploadNameType' => 'origin', 'autoRetry' => 'false', 'serverCompress' => 'false']);
                $reply = $client()->attach('file', $bytes, $name, ['Content-Type' => $mime])
                    ->post($base.'/upload?'.$query)->throw()->json('0');
                if (($reply['src'] ?? null) !== '/file/'.$key || ($reply['publicUrl'] ?? null) !== $url) {
                    throw new RuntimeException('Unexpected image storage path');
                }
            }
            $id = $db->table('cms.media_files')->insertGetId([
                'owner_type' => $ownerType, 'owner_id' => $ownerId, 'imgbed_instance' => $instance,
                'channel_name' => config('imgbed.channel'), 'file_id' => $key, 'src' => '/file/'.$key,
                'public_url' => $url, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $mime,
                'size_bytes' => strlen($bytes), 'width' => $size[0], 'height' => $size[1], 'sha256' => $sha,
                'uploaded_by' => $adminId, 'storage_status' => 'available', 'unreferenced_since' => now(),
                'tracking_state' => $remote === null ? 'tracked' : 'pending_review',
            ]);
            return ['media_id' => (string) $id, 'url' => $url];
        });
    }

    /**
     * Replace the current (non-historical) references of one authorized field.
     * The caller supplies ALL desired media IDs and holds the business transaction.
     * Owner, app, module and entity are server-derived, never request scope.
     */
    public function checkMediaReferences(
        string $appKey, string $ownerType, int $ownerId, string $moduleKey,
        string $entityType, string $entityId, string $fieldKey, string $usageState, array $mediaIds
    ): void {
        $db = DB::connection('pgsql');
        if (!$db->transactionLevel()) {
            throw new \LogicException('Save the entity and media references in one pgsql transaction');
        }
        if (!in_array($usageState, ['draft', 'scheduled', 'published'], true) ||
            min(array_map('strlen', [$appKey,$ownerType,$moduleKey,$entityType,$entityId,$fieldKey])) === 0 || $ownerId < 0) {
            throw new \InvalidArgumentException('Invalid media usage context');
        }
        foreach ($mediaIds as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
                throw ValidationException::withMessages([$fieldKey => '无效的图片 ID']);
            }
        }
        $usage = ['app_key' => $appKey, 'owner_type' => $ownerType, 'owner_id' => $ownerId,
            'module_key' => $moduleKey, 'entity_type' => $entityType, 'entity_id' => $entityId, 'field_key' => $fieldKey];
        $db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 1))', [json_encode($usage, JSON_THROW_ON_ERROR)]);
        $refs = $db->table('cms.media_references')->where($usage)->whereNull('released_at')
            ->whereIn('usage_state', ['draft','scheduled','published']);
        $old = (clone $refs)->get();
        $wanted = array_unique(array_map('strval', $mediaIds));
        $ids = $old->pluck('media_id')->merge($wanted)->unique()->values();
        $files = $db->table('cms.media_files')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($wanted as $id) {
            $file = $files->get($id);
            if (!$file || $file->storage_status !== 'available' ||
                !($file->owner_type === 'platform' || ($file->owner_type === $ownerType && (int) $file->owner_id === $ownerId)
                    || $db->table('cms.media_upload_grants')->where('media_id', $id)
                        ->where(function ($q) use ($ownerType, $ownerId) {
                            $q->where('owner_type', 'platform')->orWhere(function ($q) use ($ownerType, $ownerId) {
                                $q->where('owner_type', $ownerType)->where('owner_id', $ownerId);
                            });
                        })->exists())) {
                throw ValidationException::withMessages([$fieldKey => '图片不可用或不属于当前业务主体']);
            }
        }
        $keep = $old->filter(fn ($row) => $row->usage_state === $usageState && in_array((string) $row->media_id, $wanted, true));
        (clone $refs)->whereNotIn('id', $keep->pluck('id'))->update(['released_at' => now()]);
        foreach (array_diff($wanted, $keep->pluck('media_id')->all()) as $id) {
            $db->table('cms.media_references')->insert($usage + ['media_id' => $id, 'usage_state' => $usageState]);
        }
        foreach ($ids as $id) {
            $used = $db->table('cms.media_references')->where('media_id', $id)->whereNull('released_at')->exists();
            $db->table('cms.media_files')->where('id', $id)->update(['unreferenced_since' => $used ? null : now()]);
        }
    }

    /** Compatibility with fields which already persist a public URL. Legacy URLs remain untracked. */
    public function idsForUrl(?string $url, string $fieldKey): array
    {
        if ($url === null || $url === '') return [];
        $id = DB::connection('pgsql')->table('cms.media_files')->where('public_url', $url)
            ->where('imgbed_instance', config('imgbed.instance'))->value('id');
        if (!$id && str_starts_with($url, rtrim(config('imgbed.public_base'), '/').'/'.self::FOLDER.'/')) {
            throw ValidationException::withMessages([$fieldKey => '请重新上传图片，图片尚未登记']);
        }
        return $id ? [(string) $id] : [];
    }
}
