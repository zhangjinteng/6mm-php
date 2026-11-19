<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/site_publication.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\TenantBrand\DomainConfigException;
use SixMm\Shared\TenantConfig\ConfigSnapshotWriter;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement("CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name text, status text)");
$db->statement('CREATE TABLE wl_sites (site_id integer PRIMARY KEY, tenant_id integer, site_name text, status text, entitlement_version integer, updated_at text)');
$db->statement('CREATE TABLE wl_site_modules (site_id integer, module_code text, enabled boolean)');
publicationFixture($db, []);
$db->statement('CREATE TABLE IF NOT EXISTS wl_site_email_template_item_versions (site_id integer, template_version integer, purpose text, locale text, payload text, payload_sha256 text, PRIMARY KEY(site_id,template_version,purpose,locale))');
$db->table('wl_tenants')->insert(['tenant_id' => 1, 'tenant_name' => 'Alpha', 'status' => 'enabled']);
$db->table('wl_sites')->insert(['site_id' => 10, 'tenant_id' => 1, 'site_name' => 'Alpha', 'status' => 'enabled', 'entitlement_version' => 1]);
$db->table('wl_site_domains')->insert(['site_id' => 10, 'domain' => 'alpha.example', 'is_primary' => true]);
$db->table('wl_site_config_domains')->insert(['site_id' => 10, 'domain' => 'alpha.example']);
$auth = ['version' => 2, 'registrationClass' => 'white_label', 'emailRegistrationEnabled' => true,
    'emailLoginEnabled' => true, 'passwordManagementEnabled' => false];
$db->table('wl_site_auth_policies')->insert(['site_id' => 10, 'version' => 2, 'registration_class' => 'white_label',
    'email_registration_enabled' => true, 'email_login_enabled' => true, 'password_management_enabled' => false]);
$json = static fn ($value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$legacyRaw = $json(['version' => 3, 'siteName' => 'Legacy', 'defaultLocale' => 'zh-CN', 'templates' => []]);
$legacyRef = ['version' => 3, 'siteName' => 'Legacy', 'defaultLocale' => 'zh-CN', 'sha256' => hash('sha256', $legacyRaw)];
$db->table('wl_site_email_templates')->insert(['site_id' => 10, 'version' => 3, 'site_name' => 'Legacy', 'default_locale' => 'zh-CN', 'templates' => '[]']);
$db->table('wl_site_email_template_versions')->insert(['site_id' => 10, 'template_version' => 3,
    'payload' => $legacyRaw, 'payload_sha256' => $legacyRef['sha256'], 'published_snapshot_version' => 8]);
$site = ['tenantId' => 1, 'siteId' => 10, 'domains' => ['alpha.example'], 'status' => 'enabled',
    'entitlementVersion' => 1, 'entitlements' => [], 'authPolicy' => $auth, 'emailTemplateRef' => $legacyRef];
$putSnapshot = static function (int $version, int $schema, array $payload) use ($db, $json): void {
    if (!$db->table('wl_site_config_publications')->where('publication_version', $version)->exists()) {
        $db->table('wl_site_config_publications')->insert(['publication_version' => $version]);
    }
    $raw = $json($payload);
    $db->table('wl_site_config_snapshots')->insert(['site_id' => 10, 'config_version' => $version,
        'schema_version' => $schema, 'payload' => $raw, 'payload_sha256' => hash('sha256', $raw)]);
};
$putSnapshot(8, 4, $site);
$writer = new ConfigSnapshotWriter($db->table(...), static fn ($message) => throw new DomainConfigException($message));
$checks = 0;
$check = static function (bool $condition) use (&$checks): void {
    ++$checks;
    if (!$condition) throw new RuntimeException('Snapshot format check failed #'.$checks);
};
$reject = static function (callable $operation) use ($check): void {
    try { $operation(); }
    catch (DomainConfigException $e) { $check(true); return; }
    throw new RuntimeException('Invalid snapshot/reference was accepted');
};
$check($writer->latest(1, 10)[0] === 8);
$reject(static fn () => $writer->latest(2, 10));

$templates = [];
$manifestItems = [];
foreach (['register', 'login', 'password_set', 'password_reset'] as $purpose) {
    foreach (['zh-CN', 'en'] as $locale) {
        $body = $json(['purpose' => $purpose, 'locale' => $locale, 'subject' => 'Keep subject',
            'textBody' => 'Keep body exactly', 'htmlBody' => '<p>Keep body exactly</p>']);
        $hash = hash('sha256', $body);
        $templates[] = ['site_id' => 10, 'template_version' => 4, 'purpose' => $purpose, 'locale' => $locale,
            'payload' => $body, 'payload_sha256' => $hash];
        $manifestItems[] = ['purpose' => $purpose, 'locale' => $locale, 'sha256' => $hash];
    }
}
$manifest = $json(['version' => 4, 'siteName' => 'Published rows', 'defaultLocale' => 'zh-CN',
    'logoUrl' => 'https://alpha.example/logo.png', 'items' => $manifestItems]);
$rowsRef = ['storage' => 'rows', 'version' => 4, 'siteName' => 'Published rows', 'defaultLocale' => 'zh-CN',
    'sha256' => hash('sha256', $manifest)];
$site['emailTemplateRef'] = $rowsRef;
$putSnapshot(9, 5, $site);
$db->table('wl_site_email_template_versions')->insert(['site_id' => 10, 'template_version' => 4,
    'payload' => $manifest, 'payload_sha256' => $rowsRef['sha256'], 'published_snapshot_version' => 9]);
$db->table('wl_site_email_template_item_versions')->insert($templates);
$beforeVersions = $db->table('wl_site_email_template_versions')->orderBy('template_version')->get()->all();
$beforeItems = $db->table('wl_site_email_template_item_versions')->orderBy('purpose')->orderBy('locale')->get()->all();
$check($writer->latest(1, 10)[0] === 9); // Never filter to an older v4 row.
// Frozen legacy sources are irrelevant once this site's published reference uses rows.
$db->table('wl_site_email_templates')->where('site_id', 10)->update(['templates' => 'not legacy JSON']);

$publish = static function (callable $stage) use ($db, $writer): array {
    return $db->transaction(static function () use ($stage, $writer): array {
        $writer->lock();
        $expected = $writer->latest(1, 10)[0];
        $stage();
        return $writer->append(1, 10, $expected, 7, 'platform-admin:7', 'Non-email configuration update');
    });
};

// The upstream publisher can omit a false password-management flag. Only that
// missing key is equivalent to an explicit boolean; no other coercion is allowed.
$authWithoutPassword = $auth;
unset($authWithoutPassword['passwordManagementEnabled']);
$mismatch = '认证策略与已发布快照不一致';
$invalidPasswordFlag = 'passwordManagementEnabled 必须是布尔值';
foreach ([
    'omitted-false' => [$authWithoutPassword, false, null],
    'reordered' => [array_reverse($auth, true), false, null],
    'omitted-true' => [$authWithoutPassword, true, $mismatch],
    'false-true' => [$auth, true, $mismatch],
    'null-flag' => [array_replace($auth, ['passwordManagementEnabled' => null]), false, $invalidPasswordFlag],
    'string-flag' => [array_replace($auth, ['passwordManagementEnabled' => 'false']), false, $invalidPasswordFlag],
    'numeric-flag' => [array_replace($auth, ['passwordManagementEnabled' => 0]), false, $invalidPasswordFlag],
    'wrong-version' => [array_replace($auth, ['version' => 3]), false, $mismatch],
    'string-version' => [array_replace($auth, ['version' => '2']), false, $mismatch],
    'wrong-email-flag' => [array_replace($auth, ['emailRegistrationEnabled' => false]), false, $mismatch],
    'string-email-flag' => [array_replace($auth, ['emailLoginEnabled' => 'true']), false, $mismatch],
    'extra-field' => [$auth + ['unknown' => false], false, $mismatch],
    'absent-policy' => [null, false, $mismatch],
    'non-object-policy' => [[], false, $mismatch],
] as $case => [$publishedAuth, $currentPasswordFlag, $expectedError]) {
    $db->beginTransaction();
    try {
        $existing = $site;
        if ($publishedAuth === null) unset($existing['authPolicy']);
        else $existing['authPolicy'] = $publishedAuth;
        $putSnapshot(10, 5, $existing);
        $db->table('wl_site_auth_policies')->where('site_id', 10)->update(['password_management_enabled' => $currentPasswordFlag]);
        $policyBefore = $db->table('wl_site_auth_policies')->where('site_id', 10)->first();
        $previousBefore = $db->table('wl_site_config_snapshots')->where('config_version', 10)->first();
        $publicationCount = $db->table('wl_site_config_publications')->count();
        $error = null;
        try {
            $publish(static fn () => $db->table('wl_site_domains')->insert(['site_id' => 10, 'domain' => 'new.alpha.example', 'is_primary' => false]));
        } catch (DomainConfigException $e) { $error = $e->getMessage(); }
        if ($error !== $expectedError) throw new RuntimeException('Unexpected auth comparison for '.$case.': '.($error ?? '(accepted)'));
        $check($db->table('wl_site_domains')->where('domain', 'new.alpha.example')->exists() === ($expectedError === null));
        $check($db->table('wl_site_config_publications')->count() === $publicationCount + (int)($expectedError === null));
        $check($db->table('wl_site_config_snapshots')->where('config_version', 10)->first() == $previousBefore);
        $check($db->table('wl_site_auth_policies')->where('site_id', 10)->first() == $policyBefore);
        $check($db->table('wl_site_auth_policy_audits')->count() === 0);
        if ($expectedError === null) {
            $newSnapshot = $writer->latest(1, 10)[1];
            $check((array)$newSnapshot->authPolicy === $auth);
            $check((array)$newSnapshot->emailTemplateRef === $rowsRef);
        }
    } finally { $db->rollBack(); }
}

foreach (['domain', 'module', 'status'] as $change) {
    $publish(static function () use ($db, $change): void {
        if ($change === 'domain') $db->table('wl_site_domains')->insert(['site_id' => 10, 'domain' => 'backup.example', 'is_primary' => false]);
        if ($change === 'module') $db->table('wl_site_modules')->insert(['site_id' => 10, 'module_code' => 'contract', 'enabled' => true]);
        if ($change === 'status') $db->table('wl_tenants')->where('tenant_id', 1)->update(['status' => 'disabled']);
    });
    $latest = $db->table('wl_site_config_snapshots')->where('site_id', 10)->orderByDesc('config_version')->first();
    $payload = json_decode($latest->payload, true, 512, JSON_THROW_ON_ERROR);
    $check((int) $latest->schema_version === 5);
    $check($payload['emailTemplateRef'] === $rowsRef);
    $check(!array_key_exists('schemaVersion', $payload));
    $check(hash('sha256', $latest->payload) === $latest->payload_sha256);
}
$check($db->table('wl_site_email_template_versions')->orderBy('template_version')->get()->all() == $beforeVersions);
$check($db->table('wl_site_email_template_item_versions')->orderBy('purpose')->orderBy('locale')->get()->all() == $beforeItems);
$check($db->table('wl_site_email_templates')->where('site_id', 10)->value('version') === 3);
$check($db->table('wl_site_email_template_audits')->count() === 0);
$count = $db->table('wl_site_config_snapshots')->count();
$check(!$publish(static function (): void {})['changed']);
$check($db->table('wl_site_config_snapshots')->count() === $count);

// Empty legacy storage is v4 and is retained instead of dropped or incremented.
$db->beginTransaction();
try {
    $db->table('wl_site_email_templates')->where('site_id', 10)->update(['templates' => '[]']);
    $latest = $db->table('wl_site_config_snapshots')->orderByDesc('config_version')->first();
    $legacySite = json_decode($latest->payload, true, 512, JSON_THROW_ON_ERROR);
    $legacySite['emailTemplateRef'] = ['storage' => ''] + $legacyRef;
    $raw = $json($legacySite);
    $db->table('wl_site_config_snapshots')->where('config_version', $latest->config_version)->update([
        'schema_version' => 4, 'payload' => $raw, 'payload_sha256' => hash('sha256', $raw),
    ]);
    $publish(static fn () => $db->table('wl_site_modules')->where('module_code', 'contract')->update(['enabled' => false]));
    $legacyLatest = $db->table('wl_site_config_snapshots')->orderByDesc('config_version')->first();
    $check((int)$legacyLatest->schema_version === 4);
    $check(json_decode($legacyLatest->payload, true)['emailTemplateRef'] === $legacySite['emailTemplateRef']);
} finally { $db->rollBack(); }

// Every malformed newest record must fail instead of selecting an older valid record.
foreach (['future-schema', 'unknown-storage', 'schema-mismatch', 'bad-digest', 'wrong-site', 'malformed-json', 'invalid-reference'] as $case) {
    $db->beginTransaction();
    try {
        $latest = $db->table('wl_site_config_snapshots')->orderByDesc('config_version')->first();
        $value = json_decode($latest->payload, true, 512, JSON_THROW_ON_ERROR);
        $schema = 5;
        if ($case === 'future-schema') $schema = 6;
        if ($case === 'unknown-storage') $value['emailTemplateRef']['storage'] = 'future';
        if ($case === 'schema-mismatch') $schema = 4;
        if ($case === 'wrong-site') $value['siteId'] = 20;
        if ($case === 'invalid-reference') $value['emailTemplateRef']['version'] = '4';
        $raw = $case === 'malformed-json' ? '{' : $json($value);
        $db->table('wl_site_config_snapshots')->where('config_version', $latest->config_version)->update([
            'schema_version' => $schema, 'payload' => $raw,
            'payload_sha256' => $case === 'bad-digest' ? str_repeat('0', 64) : hash('sha256', $raw),
        ]);
        $reject(static fn () => $writer->latest(1, 10));
    } finally { $db->rollBack(); }
}

foreach (['missing-manifest', 'manifest-hash', 'missing-item', 'extra-item', 'item-hash', 'item-identity'] as $case) {
    $db->beginTransaction();
    try {
        if ($case === 'missing-manifest') $db->table('wl_site_email_template_versions')->where('template_version', 4)->delete();
        if ($case === 'manifest-hash') $db->table('wl_site_email_template_versions')->where('template_version', 4)->update(['payload_sha256' => str_repeat('0', 64)]);
        if ($case === 'missing-item') $db->table('wl_site_email_template_item_versions')->where('purpose', 'login')->where('locale', 'en')->delete();
        if ($case === 'extra-item') $db->table('wl_site_email_template_item_versions')->insert(array_replace($templates[0], ['locale' => 'fr']));
        if ($case === 'item-hash') $db->table('wl_site_email_template_item_versions')->where('purpose', 'login')->where('locale', 'en')->update(['payload_sha256' => str_repeat('0', 64)]);
        if ($case === 'item-identity') $db->table('wl_site_email_template_item_versions')->where('purpose', 'login')->where('locale', 'en')->update(['payload' => $templates[0]['payload'], 'payload_sha256' => $templates[0]['payload_sha256']]);
        $publications = $db->table('wl_site_config_publications')->count();
        $entitlement = $db->table('wl_sites')->where('site_id', 10)->value('entitlement_version');
        $reject(static fn () => $publish(static fn () => $db->table('wl_site_modules')->insert([
            'site_id' => 10, 'module_code' => 'must_roll_back', 'enabled' => true,
        ])));
        $check($db->table('wl_site_config_publications')->count() === $publications);
        $check(!$db->table('wl_site_modules')->where('module_code', 'must_roll_back')->exists());
        $check($db->table('wl_sites')->where('site_id', 10)->value('entitlement_version') === $entitlement);
    } finally { $db->rollBack(); }
}

echo "Site snapshot formats: {$checks} checks passed\n";
