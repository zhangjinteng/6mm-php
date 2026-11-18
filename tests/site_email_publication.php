<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/site_publication.php';
require __DIR__.'/fixtures/email_rows.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use SixMm\Shared\TenantConfig\{EmailConfigException, EmailTemplateRules, SiteEmailPublisher};

$capsule = new Capsule(); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
$db->statement("CREATE TABLE wl_tenants (tenant_id integer PRIMARY KEY, tenant_name text, status text)");
$db->statement('CREATE TABLE wl_sites (site_id integer PRIMARY KEY, tenant_id integer, site_name text, status text, entitlement_version integer, updated_at text)');
$db->statement('CREATE TABLE wl_site_modules (site_id integer, module_code text, enabled boolean)');
publicationFixture($db, []); emailRowsFixture($db);
$db->table('wl_tenants')->insert([['tenant_id' => 1, 'tenant_name' => 'Alpha', 'status' => 'enabled'], ['tenant_id' => 2, 'tenant_name' => 'Beta', 'status' => 'enabled']]);
foreach ([10, 11, 20] as $site) {
    $db->table('wl_sites')->insert(['site_id' => $site, 'tenant_id' => $site === 20 ? 2 : 1,
        'site_name' => 'Site '.$site, 'status' => $site === 11 ? 'disabled' : 'enabled', 'entitlement_version' => 1]);
    $db->table('wl_site_domains')->insert(['site_id' => $site, 'domain' => 'site'.$site.'.example', 'is_primary' => true]);
}
$publisher = new SiteEmailPublisher($db, ['cdn.example']);
$white = new SiteEmailPublisher($db, ['cdn.example'], true);
$checks = 0;
$check = static function (bool $value) use (&$checks): void { if (!$value) throw new RuntimeException('Email check failed #'.($checks + 1)); ++$checks; };
$reject = static function (callable $operation) use ($check): void {
    try { $operation(); } catch (EmailConfigException $e) { $check(true); return; }
    throw new RuntimeException('Invalid email operation was accepted');
};
$save = static fn (array $patch) => $publisher->publish(1, 10, $patch, 7, 'platform-admin:7', '更新邮件品牌');
$encoded=EmailTemplateRules::json(['html'=>'<p>&</p>','literal'=>'\\u003C']);
$check(str_contains($encoded,'\\u003cp\\u003e\\u0026') && json_decode($encoded,true)['literal']==='\\u003C');
foreach (['EN-us'=>'en-US','zh_hant_tw'=>'zh-Hant-TW','es-419'=>'es-419','en-x-TEST'=>'en-x-test','en-u-ca-gregory'=>'en-u-ca-gregory',
    'en-x-a-a'=>'en-x-a-a','en-b-foo-a-bar'=>'en-a-bar-b-foo'] as $raw=>$canonical) {
    $check(EmailTemplateRules::locale($raw)===$canonical);
}
$reject(static fn()=>EmailTemplateRules::locale('en-u-ca-gregory-u-nu-latn'));
$reject(static fn()=>EmailTemplateRules::locale('de-1901-1901'));
$detail = $publisher->detail(1, 10);
$check($detail['version'] === '0' && $detail['site_name'] === 'Site 10' && $detail['logo_url'] === '' && $detail['default_locale'] === 'zh-CN');
$check($db->table('wl_site_email_configs')->count() === 0); // Read never initializes.
$reject(static fn () => $white->detail(1, 20));
$reject(static fn () => $white->detail(1, 11));
$created = $publisher->initializeIfMissing(1, 10, 7, 'platform-admin:7', '初始化默认邮件配置');
$check($created['initialized'] && $created['detail']['version'] === '1');
$check($created['detail']['publication_version'] !== '1');
$check($db->table('wl_site_email_template_items')->where('site_id', 10)->count() === 8);
$check(!$db->table('wl_site_email_templates')->where('site_id', 10)->exists());
$snapshot = $db->table('wl_site_config_snapshots')->where('site_id', 10)->orderByDesc('config_version')->first();
$check($snapshot->schema_version === 5 && json_decode($snapshot->payload)->emailTemplateRef->storage === 'rows');
$check(!str_contains($snapshot->payload, 'logoUrl') && !str_contains($snapshot->payload, 'schemaVersion'));
$check($publisher->initializeIfMissing(1, 10, 7, 'platform-admin:7', '再次检查') === ['initialized' => false]);
$sourceBodies = $db->table('wl_site_email_template_items')->where('site_id', 10)->orderBy('purpose')->orderBy('locale')->get()->all();
$publishedBodies = $db->table('wl_site_email_template_item_versions')->where('site_id', 10)->where('template_version', 1)->orderBy('purpose')->orderBy('locale')->get(['purpose','locale','payload','payload_sha256'])->all();
$policyBefore = $db->table('wl_site_auth_policies')->where('site_id', 10)->first();
$result = $save(['expectedVersion' => '1', 'logoUrl' => 'https://cdn.example/brand.png']);
$check($result['detail']['version'] === '2' && $result['detail']['logo_url'] === 'https://cdn.example/brand.png');
$check($db->table('wl_site_email_template_items')->where('site_id', 10)->orderBy('purpose')->orderBy('locale')->get()->all() == $sourceBodies);
$check($db->table('wl_site_email_template_item_versions')->where('site_id', 10)->where('template_version', 2)->orderBy('purpose')->orderBy('locale')->get(['purpose','locale','payload','payload_sha256'])->all() == $publishedBodies);
$check($db->table('wl_site_auth_policies')->where('site_id', 10)->first() == $policyBefore);
$reject(static fn () => $save(['expectedVersion' => '1', 'siteName' => 'Stale']));
$counts = [$db->table('wl_site_config_publications')->count(), $db->table('wl_site_email_template_audits')->count()];
foreach ([['templates' => []], ['policy' => []], ['SMTP' => []], ['operatorId' => 999], ['logoUrl' => 'https://evil.example/logo.png'],
    ['logoUrl' => 'http://cdn.example/logo.png'], ['logoUrl' => 'https://cdn.example.evil.test/logo.png'], ['logoUrl' => 'https://user:pass@cdn.example/logo.png'],
    ['siteName' => str_repeat('名',129)], ['defaultLocale' => 'not a locale'], ['logoUrl' => 1]] as $bad) {
    $reject(static fn () => $save(['expectedVersion' => '2'] + $bad));
}
$check($counts === [$db->table('wl_site_config_publications')->count(), $db->table('wl_site_email_template_audits')->count()]);
$unchanged = $save(['expectedVersion' => '2', 'siteName' => '', 'defaultLocale' => null]);
$check(!$unchanged['changed'] && $unchanged['detail']['version'] === '2' && $unchanged['detail']['logo_url'] === 'https://cdn.example/brand.png');
$cleared = $save(['expectedVersion' => '2', 'siteName' => 'Brand new', 'logoUrl' => '', 'defaultLocale' => 'EN-us']);
$check($cleared['detail']['logo_url'] === '' && $cleared['detail']['default_locale'] === 'en-US' && $cleared['detail']['version'] === '3');
$check(!$db->table('wl_site_email_configs')->where('site_id', 20)->exists());

// Failure after every pending table write rolls all six publication/source tables back.
$before = [];
$tables = ['wl_site_email_configs','wl_site_email_template_items','wl_site_email_template_versions','wl_site_email_template_item_versions',
    'wl_site_email_template_audits','wl_site_config_snapshots','wl_site_config_publications'];
foreach ($tables as $table) $before[$table] = json_encode($db->table($table)->get());
try {
    $publisher->publish(1, 10, ['expectedVersion' => 3, 'siteName' => 'Rollback'], 7, 'white-label-admin:7', '测试回滚',
        static fn () => throw new RuntimeException('audit failure'));
    throw new RuntimeException('Failed audit was accepted');
} catch (RuntimeException $e) { $check($e->getMessage() === 'audit failure'); }
foreach ($tables as $table) $check(json_encode($db->table($table)->get()) === $before[$table]);

// Source drift and invalid published bodies fail closed, not via legacy/default fallback.
$db->beginTransaction();
try {
    $db->table('wl_site_email_template_items')->where('site_id',10)->where('purpose','login')->where('locale','en')->update(['text_body' => 'Unpublished']);
    $reject(static fn () => $save(['expectedVersion' => 3, 'siteName' => 'Cannot publish drift']));
} finally { $db->rollBack(); }
$db->beginTransaction();
try {
    $db->table('wl_site_email_template_item_versions')->where('site_id',10)->where('template_version',3)->where('purpose','login')->where('locale','en')->delete();
    $reject(static fn () => $save(['expectedVersion' => 3, 'siteName' => 'Cannot hide missing bodies']));
} finally { $db->rollBack(); }

// Existing v4 templates migrate without changing any subject/text/html or old records.
$writer = new \SixMm\Shared\TenantConfig\ConfigSnapshotWriter($db->table(...), static fn ($m) => throw new EmailConfigException($m));
$db->transaction(static function () use ($writer): void { $writer->lock(); $writer->append(2, 20, 0, 7, 'platform-admin:7', 'Legacy fixture'); });
$legacyTemplate = ['purpose' => 'login', 'locale' => 'en', 'subject' => 'Unchanged subject', 'textBody' => "  {{code}}\nKEEP spaces  ",
    'htmlBody' => '<p>KEEP {{code}}</p>'];
$legacyBody = ['version' => 1, 'siteName' => 'Legacy brand', 'defaultLocale' => 'en', 'templates' => [$legacyTemplate]];
$legacyRaw = EmailTemplateRules::json($legacyBody);
$legacySnapshot = $db->table('wl_site_config_snapshots')->where('site_id',20)->orderByDesc('config_version')->first();
$value = json_decode($legacySnapshot->payload,true);
$value['emailTemplateRef'] = ['version'=>1, 'siteName'=>'Legacy brand','defaultLocale'=>'en','sha256'=>hash('sha256',$legacyRaw)];
$raw = EmailTemplateRules::json($value);
$db->table('wl_site_config_snapshots')->where('site_id',20)->update(['payload'=>$raw,'payload_sha256'=>hash('sha256',$raw)]);
$db->table('wl_site_email_templates')->where('site_id',20)->update(['site_name'=>'Legacy brand','default_locale'=>'en','templates'=>EmailTemplateRules::json([$legacyTemplate])]);
$db->table('wl_site_email_template_versions')->where('site_id',20)->update(['payload'=>$legacyRaw,'payload_sha256'=>hash('sha256',$legacyRaw)]);
$oldLegacy = $db->table('wl_site_email_templates')->where('site_id',20)->first();
$check($publisher->detail(2,20)['version'] === '1');
$migrated = $publisher->initializeIfMissing(2,20,7,'platform-admin:7','迁入旧模板');
$check($migrated['detail']['version'] === '2' && $migrated['detail']['site_name'] === 'Legacy brand');
$saved = $db->table('wl_site_email_template_items')->where('site_id',20)->sole();
$check($saved->subject === $legacyTemplate['subject'] && $saved->text_body === $legacyTemplate['textBody'] && $saved->html_body === $legacyTemplate['htmlBody']);
$check($db->table('wl_site_email_templates')->where('site_id',20)->first() == $oldLegacy);
$check($db->table('wl_site_email_template_versions')->where('site_id',20)->where('template_version',1)->value('payload') === $legacyRaw);
$check($publisher->initializeIfMissing(2,20,7,'platform-admin:7','再检查') === ['initialized'=>false]);

foreach (['{{code}}', '{{validMinutes}}', '{{expiresAt}}', '{{logoUrl}}'] as $subject) {
    $reject(static fn () => EmailTemplateRules::template(array_replace($legacyTemplate,['subject'=>$subject]), ['cdn.example']));
}
foreach (['<script>alert(1)</script>', '<img src="https://evil.example/a.png">', '<img src="{{logoUrl}}/extra">',
    '<p>{{logoUrl}}</p>', '<p onclick="alert(1)">x</p>'] as $html) {
    $reject(static fn () => EmailTemplateRules::template(array_replace($legacyTemplate,['htmlBody'=>$html]), ['cdn.example']));
}
$reject(static fn () => (new SiteEmailPublisher($db, [], false, null, false))->publish(1,10,['expectedVersion'=>3],7,'platform-admin:7','未就绪'));
// Both administration hosts use this save path. A logo change and its mail manifest
// must be published together, keeping every existing template byte unchanged.
$store = new \SixMm\Shared\TenantBrand\DomainConfigStore($db, false, null, $publisher);
$changeLogo = function (?string $logo) use ($store): void {
    $site = $store->detail(['tenant_id'=>1])['sites'][0];
    $store->save(['tenant_id'=>1, 'site_id'=>10, 'domains'=>$site['domains'],
        'expected_config_version'=>$site['config_version'], 'expected_revision'=>$site['revision'],
        'expected_brand_revision'=>$site['brand_revision'], 'logo_url'=>$logo], 7, static fn()=>null, 'white_label');
};
$changeLogo('https://cdn.example/website.png');
$mail = $publisher->detail(1,10);
$check($mail['site_name'] === 'Alpha' && $mail['logo_url'] === 'https://cdn.example/website.png' && $mail['default_locale'] === 'zh-CN');
$check($publisher->syncWebsiteBrand(1,10,7,'platform-admin:7')['changed'] === false);
$changeLogo(null);
$check($publisher->detail(1,10)['logo_url'] === '');
$check($db->table('wl_site_email_template_items')->where('site_id',10)->orderBy('purpose')->orderBy('locale')->get()->all() == $sourceBodies);
$reject(static fn()=>$changeLogo('https://other.example/logo.png'));
$check($publisher->detail(1,10)['logo_url'] === '');
echo "Site email publication: {$checks} checks passed\n";
