<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

use SixMm\Shared\TenantCompliance\ComplianceConfigStore;

final class AgreementTemplateSnapshot
{
    public static function validLocale(string $locale): bool
    {
        return strlen($locale) <= 32 && (bool)preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $locale);
    }

    /** Copy only published fields, retaining template variables without substituting a tenant. */
    public static function payload(object $row): string
    {
        if ($row->scope_type !== 'platform' || $row->site_id !== null || (int)$row->id < 1
            || !self::validLocale((string)$row->locale) || (int)$row->published_version < 1
            || !isset(ComplianceConfigStore::TYPES[$row->agreement_type])
            || !is_string($row->code) || !trim($row->code) || !is_string($row->name) || !trim($row->name)) {
            throw new \UnexpectedValueException('Invalid published platform template');
        }
        $sections = json_decode((string)$row->published_sections, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($sections) || !array_is_list($sections) || !$sections || count($sections) > 100) {
            throw new \UnexpectedValueException('Invalid published template sections');
        }
        $published = []; $keys = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !is_string($section['key'] ?? null) || !trim($section['key'])
                || isset($keys[$section['key']]) || !is_string($section['title'] ?? null) || !trim($section['title'])
                || !is_string($section['body_html'] ?? null) || !trim($section['body_html'])) {
                throw new \UnexpectedValueException('Invalid published template chapter');
            }
            $keys[$section['key']] = true;
            $published[] = ['key'=>$section['key'], 'title'=>$section['title'], 'body_html'=>$section['body_html']];
        }
        $payload = json_encode(['templateId'=>(string)$row->id, 'code'=>$row->code, 'agreementType'=>$row->agreement_type,
            'locale'=>$row->locale, 'name'=>$row->name, 'enabled'=>(bool)$row->is_enabled,
            'publishedVersion'=>(string)$row->published_version, 'sections'=>$published],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($payload) > 1048576) throw new \UnexpectedValueException('Published template exceeds capacity');
        return $payload;
    }
}
