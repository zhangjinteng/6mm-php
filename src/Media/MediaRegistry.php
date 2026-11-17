<?php

declare(strict_types=1);

namespace SixMm\Shared\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class MediaRegistry
{
    private const FOLDER = 'media/upload_files';

    public function upload(UploadedFile $file, ?int $adminId, ?int $tenantId = null): array
    {
        $image = ImageFile::uploaded($file);
        $bytes = $image['bytes']; $mime = $image['mime']; $extension = $image['extension'];
        $size = [$image['width'], $image['height']];
        $name = md5($bytes).'.'.$extension;
        $key = self::FOLDER.'/'.$name;
        $sha = hash('sha256', $bytes);
        $db = DB::connection('pgsql');
        $instance = config('imgbed.instance');
        $ownerType = $tenantId === null ? 'platform' : 'white_label';
        $ownerId = $tenantId ?? 0;
        if ($tenantId !== null && $tenantId < 1) throw new \InvalidArgumentException('Invalid tenant');
        $uploaded = false;
        try {
            return $db->transaction(function () use ($file, $adminId, $db, $instance, $bytes, $mime, $size, $key, $name, $sha, $ownerType, $ownerId, &$uploaded): array {
                // Serialize only uploads targeting the same physical object, including initial registration.
                $db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$instance.':'.$key]);
                $existing = $db->table('cms.media_files')->where('imgbed_instance', $instance)
                    ->where(fn ($q) => $q->where('file_id', $key)->orWhere('sha256', $sha))
                    ->orderBy('id')->lockForUpdate()->first();
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
                $publicBase = rtrim(config('imgbed.public_base'), '/');
                $token = config('imgbed.token');
                if (!$token || !str_starts_with($base, 'https://') || !str_starts_with($publicBase, 'https://')) {
                    throw new MediaUploadException('MEDIA_STORAGE_NOT_CONFIGURED', '图片存储服务尚未正确配置，请联系管理员', 503);
                }
                // Database miss: upload directly. A prior uncommitted attempt may leave a remote
                // copy; the bed preserves it and returns hash(n).ext for this new physical object.
                $query = http_build_query(['uploadChannel' => 's3', 'channelName' => config('imgbed.channel'),
                    'uploadFolder' => self::FOLDER, 'uploadNameType' => 'origin', 'autoRetry' => 'false', 'serverCompress' => 'false']);
                try {
                    $response = Http::withToken($token)->withoutRedirecting()->timeout(20)
                        ->attach('file', $bytes, $name, ['Content-Type' => $mime])
                        ->post($base.'/upload?'.$query)->throw();
                } catch (ConnectionException $e) {
                    $timeout = str_contains($e->getMessage(), 'cURL error 28');
                    throw new MediaUploadException('MEDIA_STORAGE_UNAVAILABLE', $timeout
                        ? '图片上传超时，上传结果暂未确认，请稍后重试'
                        : '无法连接图片存储服务，请稍后重试或联系管理员', $timeout ? 504 : 502, $e);
                } catch (RequestException $e) {
                    $rejected = in_array($e->response->status(), [401, 403], true);
                    throw new MediaUploadException($rejected ? 'MEDIA_STORAGE_REJECTED' : 'MEDIA_STORAGE_FAILED', $rejected
                        ? '图片存储服务拒绝上传，请联系管理员检查服务端凭据或访问策略'
                        : '图片存储服务上传失败，请稍后重试或联系管理员', 502, $e);
                }
                if (!$response->successful()) throw $this->invalidResponse();
                [$key, $src, $url] = $this->uploadedPath($response->json(), $name, $publicBase);
                $uploaded = true;
                $id = $db->table('cms.media_files')->insertGetId([
                    'owner_type' => $ownerType, 'owner_id' => $ownerId, 'imgbed_instance' => $instance,
                    'channel_name' => config('imgbed.channel'), 'file_id' => $key, 'src' => $src,
                    'public_url' => $url, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $mime,
                    'size_bytes' => strlen($bytes), 'width' => $size[0], 'height' => $size[1], 'sha256' => $sha,
                    'uploaded_by' => $adminId, 'storage_status' => 'available', 'unreferenced_since' => now(),
                    'tracking_state' => 'tracked',
                ]);
                return ['media_id' => (string) $id, 'url' => $url];
            });
        } catch (QueryException $e) {
            throw new MediaUploadException($uploaded ? 'MEDIA_REGISTRATION_FAILED' : 'MEDIA_REGISTRY_UNAVAILABLE', $uploaded
                ? '图片已上传，但登记失败，请稍后重试'
                : '图片记录读取失败，请稍后重试或联系管理员', 503, $e);
        }
    }

    /** Accept only the submitted content filename and the bed's non-destructive (n) suffix. */
    private function uploadedPath(mixed $body, string $name, string $publicBase): array
    {
        $reply = is_array($body) ? ($body[0] ?? null) : null;
        if (!is_array($reply) || !is_string($reply['src'] ?? null) || !is_string($reply['publicUrl'] ?? null)) {
            throw $this->invalidResponse();
        }
        $src = rawurldecode($reply['src']);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (!preg_match('~\A/file/'.preg_quote(self::FOLDER.'/'.$stem, '~').'(?:\([1-9][0-9]*\))?\.'.preg_quote($extension, '~').'\z~', $src)) {
            throw $this->invalidResponse();
        }
        $key = substr($src, strlen('/file/'));
        $encodedKey = str_replace(['(', ')'], ['%28', '%29'], $key);
        if (!in_array($reply['src'], [$src, '/file/'.$encodedKey], true) ||
            !in_array($reply['publicUrl'], [$publicBase.'/'.$key, $publicBase.'/'.$encodedKey], true)) {
            throw $this->invalidResponse();
        }
        return [$key, $src, $reply['publicUrl']];
    }

    private function invalidResponse(): MediaUploadException
    {
        return new MediaUploadException('MEDIA_STORAGE_INVALID_RESPONSE', '图片存储返回结果异常，请联系管理员');
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
