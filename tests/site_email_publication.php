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
    ['logoUrl' => 'https://cdn.example:443/logo.png'], ['logoUrl' => 'https://cdn.example:/logo.png'],
    ['logoUrl' => 'https://cdn.example/{x}'], ['logoUrl' => 'https://cdn.example/%zz'],
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
$legacyTemplate = ['purpose' => 'login', 'locale' => 'en', 'subject' => 'Unchanged subject', 'textBody' => "  {{code}}\nKEEP spaces {{expiresAt}}  ",
    'htmlBody' => '<p>KEEP {{code}} {{expiresAt}}</p>'];
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
// Hash-valid legacy templates can still be incompatible with User. Reject them
// before migration/publication, without replacing them with the new presets.
foreach (['<html><p>{{code}} {{expiresAt}}</p></html>', '<body><p>{{code}} {{expiresAt}}</p></body>', '<h1>{{code}} {{expiresAt}}</h1>',
    '<p style="position:absolute">{{code}} {{expiresAt}}</p>', '<p class="x">{{code}} {{expiresAt}}</p>',
    '<a href="https://evil.example/help">{{code}} {{expiresAt}}</a>',
    '<img src="https://cdn.example/%zz"><p>{{code}} {{expiresAt}}</p>',
    '<p>&#123&#123logoUrl&#125&#125 {{code}} {{expiresAt}}</p>',
] as $invalidHtml) {
    $db->beginTransaction();
    try {
        $invalidTemplate = array_replace($legacyTemplate, ['htmlBody' => $invalidHtml]);
        $invalidBody = array_replace($legacyBody, ['templates' => [$invalidTemplate]]);
        $invalidRaw = EmailTemplateRules::json($invalidBody);
        $invalidSnapshot = $value;
        $invalidSnapshot['emailTemplateRef']['sha256'] = hash('sha256', $invalidRaw);
        $invalidSnapshotRaw = EmailTemplateRules::json($invalidSnapshot);
        $db->table('wl_site_config_snapshots')->where('site_id',20)->update([
            'payload' => $invalidSnapshotRaw, 'payload_sha256' => hash('sha256', $invalidSnapshotRaw)]);
        $db->table('wl_site_email_templates')->where('site_id',20)->update(['templates' => EmailTemplateRules::json([$invalidTemplate])]);
        $db->table('wl_site_email_template_versions')->where('site_id',20)->update([
            'payload' => $invalidRaw, 'payload_sha256' => hash('sha256', $invalidRaw)]);
        $beforeInvalidPublication = [];
        foreach ($tables as $table) $beforeInvalidPublication[$table] = json_encode($db->table($table)->get());
        $reject(static fn () => $publisher->initializeIfMissing(2,20,7,'platform-admin:7','非法旧模板迁入'));
        foreach ($tables as $table) $check(json_encode($db->table($table)->get()) === $beforeInvalidPublication[$table]);
    } finally { $db->rollBack(); }
}
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
    $reject(static fn () => EmailTemplateRules::template(array_replace($legacyTemplate,['htmlBody'=>$html.'<p>{{code}} {{expiresAt}}</p>']), ['cdn.example']));
}
$reject(static fn () => (new SiteEmailPublisher($db, [], false, null, false))->publish(1,10,['expectedVersion'=>3],7,'platform-admin:7','未就绪'));
// Both administration hosts use this save path. A logo change and its mail manifest
// must be published together, keeping every existing template byte unchanged.
$upstreamSnapshot = $db->table('wl_site_config_snapshots')->where('site_id',10)->orderByDesc('config_version')->first();
$upstreamPayload = json_decode($upstreamSnapshot->payload, false, 512, JSON_THROW_ON_ERROR);
unset($upstreamPayload->authPolicy->passwordManagementEnabled);
$upstreamRaw = EmailTemplateRules::json($upstreamPayload);
$db->table('wl_site_config_snapshots')->where('config_version', $upstreamSnapshot->config_version)->update([
    'payload' => $upstreamRaw, 'payload_sha256' => hash('sha256', $upstreamRaw),
]);
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
$check($db->table('wl_site_config_snapshots')->where('config_version', $upstreamSnapshot->config_version)->value('payload') === $upstreamRaw);
$check($db->table('wl_site_auth_policies')->where('site_id', 10)->first() == $policyBefore);
$check($publisher->syncWebsiteBrand(1,10,7,'platform-admin:7')['changed'] === false);
$changeLogo(null);
$check($publisher->detail(1,10)['logo_url'] === '');
$check($db->table('wl_site_email_template_items')->where('site_id',10)->orderBy('purpose')->orderBy('locale')->get()->all() == $sourceBodies);
$reject(static fn()=>$changeLogo('https://other.example/logo.png'));
$check($publisher->detail(1,10)['logo_url'] === '');
// Only the managed heading is shortened; names in the manifest/body remain full.
$beforeRename = $publisher->detail(1, 10);
$longName = 'Freedex-self-operated';
$renamed = $save(['expectedVersion' => $beforeRename['version'], 'siteName' => $longName]);
$check($renamed['detail']['site_name'] === $longName);
foreach ($db->table('wl_site_email_template_items')->where('site_id', 10)->get() as $body) {
    $check(str_contains($body->html_body, 'Freedex-self-oper...') && str_contains($body->html_body, '{{siteName}}'));
}
$check(!$save(['expectedVersion' => $renamed['detail']['version']])['changed']);
// A later publication failure must roll the materialized heading back as well.
$beforeRollback = $db->table('wl_site_email_template_items')->where('site_id', 10)->orderBy('purpose')->orderBy('locale')->get()->all();
$db->unprepared("CREATE TRIGGER fail_header_publication BEFORE INSERT ON wl_site_email_template_item_versions BEGIN SELECT RAISE(ABORT, 'header rollback test'); END");
try {
    $save(['expectedVersion' => $renamed['detail']['version'], 'siteName' => 'Short']);
    throw new RuntimeException('Expected publication failure');
} catch (Illuminate\Database\QueryException $e) { $check(true); }
$db->unprepared('DROP TRIGGER fail_header_publication');
$check($publisher->detail(1, 10) === $renamed['detail']);
$check($db->table('wl_site_email_template_items')->where('site_id', 10)->orderBy('purpose')->orderBy('locale')->get()->all() == $beforeRollback);
$short = $save(['expectedVersion' => $renamed['detail']['version'], 'siteName' => 'Short']);
$check($short['detail']['site_name'] === 'Short');
foreach ($db->table('wl_site_email_template_items')->where('site_id', 10)->get() as $body) {
    $check(!str_contains($body->html_body, 'Freedex-self-oper...') && !str_contains($body->html_body, 'title='));
}

// A rollout only switches genuinely new sites. The old site can still be
// unpublished at deployment; a brand update on an already published site
// must also retain the existing default/legacy rules.
$rollout = new SiteEmailPublisher($db, ['cdn.example'], whitePresetAfterSiteId: 30);
foreach ([30, 31, 32] as $siteId) {
    $db->table('wl_sites')->insert(['site_id'=>$siteId, 'tenant_id'=>2, 'site_name'=>'Site '.$siteId,
        'status'=>'enabled', 'entitlement_version'=>1]);
    $db->table('wl_site_domains')->insert(['site_id'=>$siteId, 'domain'=>'site'.$siteId.'.example', 'is_primary'=>true]);
}
$db->transaction(static function () use ($writer): void {
    $writer->lock();
    $writer->append(2, 32, 0, 7, 'platform-admin:7', 'Existing v4 site with no email bodies');
});
$existingItems = $db->table('wl_site_email_template_items')->where('site_id', 10)->orderBy('purpose')->orderBy('locale')->get();
foreach ([30=>false, 31=>true, 32=>false] as $siteId => $isWhite) {
    $db->transaction(static function () use ($writer, $rollout, $siteId): void {
        $writer->lock();
        [$version] = $writer->latest(2, $siteId);
        $writer->append(2, $siteId, $version, 7, 'platform-admin:7', 'First email rollout fixture',
            $rollout->prepareInitialization(2, $siteId));
    });
    $rows = $db->table('wl_site_email_template_items')->where('site_id', $siteId)->get();
    $check($rows->count() === 8);
    foreach ($rows as $body) {
        $check(str_contains($body->html_body, 'border:1px solid #dedede') === $isWhite);
        $check(str_contains($body->html_body, 'ACCOUNT<br>SECURITY') !== $isWhite);
        $check(str_contains($body->html_body, '{{logoUrl}}') && str_contains($body->html_body, '{{code}}'));
        if ($isWhite) {
            $template = ['purpose'=>$body->purpose, 'locale'=>$body->locale, 'subject'=>$body->subject,
                'textBody'=>$body->text_body, 'htmlBody'=>$body->html_body];
            EmailTemplateRules::template($template, []);
            EmailTemplateRules::template($template, ['cdn.example'], 'https://cdn.example/logo.png');
            $check(!str_contains($body->html_body, 'https://'));
            $preset = collect(EmailTemplateRules::defaults())->first(fn ($r) => $r['purpose'] === $body->purpose && $r['locale'] === $body->locale);
            $check($body->text_body === $preset['textBody'] && $body->subject === $preset['subject']);
        }
    }
}
$check($db->table('wl_site_email_template_items')->where('site_id', 10)->orderBy('purpose')->orderBy('locale')->get() == $existingItems);
echo "Site email publication: {$checks} checks passed\n";
