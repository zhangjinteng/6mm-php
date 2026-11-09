<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantBrand;

use Illuminate\Database\ConnectionInterface;
use SixMm\Shared\Media\MediaRegistry;

/** Site-wide identity, mirrored on every access domain (including newly added domains). */
final class SiteBrandAssets
{
    public const FIELDS = ['template_id', 'logo_url', 'favicon_url'];

    public function __construct(private ConnectionInterface $db, private ?string $schema = null) {}

    private function domains(string $siteId)
    {
        $prefix = $this->schema ?? ($this->db->getDriverName() === 'pgsql' ? 'control_plane' : '');
        return $this->db->table(($prefix ? $prefix.'.' : '').'wl_site_domains')->where('site_id', $siteId);
    }

    public function read(string $siteId): array
    {
        $rows = $this->domains($siteId)->orderByDesc('is_primary')->orderBy('domain')->get();
        $row = $rows->first();
        return array_combine(self::FIELDS, array_map(static fn ($key) => $row->{$key} ?? null, self::FIELDS));
    }

    public static function revision(array $values): string
    {
        return hash('sha256', json_encode(array_intersect_key($values, array_flip(self::FIELDS)), JSON_THROW_ON_ERROR));
    }

    public static function rules(): array
    {
        return ['expected_brand_revision' => 'sometimes|required|string|size:64|regex:/^[a-f0-9]+$/D',
            'template_id' => 'sometimes|nullable|string|in:template-1,template-2',
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url', 'regex:~^https://[^/@?#\\s]+(?:[/?#][^\\s]*)?$~iD'],
            'favicon_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url', 'regex:~^https://[^/@?#\\s]+(?:[/?#][^\\s]*)?$~iD']];
    }

    public static function desired(array $input, array $before): array
    {
        $changes = array_intersect_key($input, array_flip(self::FIELDS));
        if ($changes === []) return $before; // Old domain-only clients must preserve branding.
        if (!is_string($input['expected_brand_revision'] ?? null)
            || !hash_equals(self::revision($before), $input['expected_brand_revision'])) {
            throw new DomainConfigException('主题或标识已变化，请重新加载后保存');
        }
        $values = array_replace($before, $changes);
        if ($values['template_id'] !== null && !in_array($values['template_id'], ['template-1', 'template-2'], true)) {
            throw new DomainConfigException('请选择主题1或主题2');
        }
        foreach (['logo_url', 'favicon_url'] as $key) {
            $url = $values[$key];
            if ($url !== null && (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
                || !preg_match('~^https://[^/@?#\s]+(?:[/?#][^\s]*)?$~iD', $url))) {
                throw new DomainConfigException('图片地址必须为有效的 HTTPS 地址');
            }
        }
        return $values;
    }

    /** Must be called inside the host's domain transaction, after its authorization and revision checks. */
    public function write(string $siteId, int $tenantId, array $values, array $before): void
    {
        if (!$this->db->transactionLevel()) throw new \LogicException('Brand changes require a transaction');
        // A pre-migration domain-only client can keep editing domains when it has no branding yet.
        if (array_filter($values, static fn ($v) => $v !== null) || $values !== $before) {
            $this->domains($siteId)->update($values);
        }
        if ($this->db->getDriverName() !== 'pgsql') return;
        $media = new MediaRegistry();
        foreach (['logo_url', 'favicon_url'] as $field) {
            if ($values[$field] === $before[$field]) continue;
            $media->checkMediaReferences('platform_admin', 'white_label', $tenantId, 'brand', 'site', $siteId,
                $field, 'published', $media->idsForUrl($values[$field], $field));
        }
    }
}
