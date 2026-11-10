<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use SixMm\Shared\TenantConfig\ThemeRedisPublisher;

// An explicitly supplied owned local Unix socket only: never load application Redis credentials.
$socket = getenv('THEME_REDIS_TEST_SOCKET');
if (!$socket || !str_starts_with($socket, sys_get_temp_dir().'/') || !file_exists($socket)) {
    throw new RuntimeException('Supply THEME_REDIS_TEST_SOCKET for an isolated temporary Redis');
}
$raw = new Redis();
$raw->connect($socket);
$client = new class($raw) {
    public function __construct(private Redis $raw) {}
    public function eval($script, $keys, ...$arguments) { return $this->raw->eval($script, $arguments, $keys); }
};
$writer = new ThemeRedisPublisher(fn () => $client, 'isolated:theme', 'isolated:template');
$checks = 0;
$check = static function (bool $ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('Theme Lua check failed #'.$checks); };
$row = static function (int $site, string $version, string $name = 'Test'): object {
    $payload = json_encode(['siteId' => (string)$site, 'name' => $name]);
    return (object)['site_id'=>$site,'config_version'=>$version,'schema_version'=>1,'payload'=>$payload,
        'payload_sha256'=>hash('sha256',$payload),'previous_config_version'=>null];
};
$key = $writer->key(910024);
$raw->del($key, $writer->key(910025)); // only this isolated test's two keys
$writer->publish($row(910024, '9007199254740992'));
$check($raw->hGet($key,'config_version') === '9007199254740992');
$writer->publish($row(910024, '9007199254740993', 'New'));
$writer->publish($row(910024, '9007199254740992', 'Old'));
$check($raw->hGet($key,'config_version') === '9007199254740993');
$check(json_decode($raw->hGet($key,'payload'))->name === 'New');
$writer->publish($row(910024, '9007199254740993', 'New'));
$check($raw->hLen($key) === 6);
$raw->expire($key, 60);
$writer->publish($row(910024, '9007199254740993', 'New'));
$check($raw->ttl($key) === -1);
try { $writer->publish($row(910024,'9007199254740993','Conflict')); throw new LogicException('Conflict ignored'); }
catch (RuntimeException $e) { $check(true); } // PhpRedis may return false rather than throw for a Lua error reply.
$check(json_decode($raw->hGet($key,'payload'))->name === 'New');
$writer->publish($row(910025,'1','Other site'));
$check($raw->hGet($writer->key(910025),'config_version') === '1');
$raw->del($key);
$raw->set($key,'wrong-type');
try { $writer->publish($row(910024,'1')); throw new LogicException('Wrong type ignored'); }
catch (RuntimeException $e) { $check(true); }
$check($raw->get($key) === 'wrong-type');
$raw->del($key);
$writer->publish($row(910024,'1'));
$raw->hSet($key,'config_version','corrupt');
try { $writer->publish($row(910024,'2')); throw new LogicException('Corrupt version ignored'); }
catch (RuntimeException $e) { $check(true); }
$check($raw->hGet($key,'config_version') === 'corrupt');
$raw->del($key, $writer->key(910025));
$templateRow=static function (string $version, string $locale='en', string $title='Published'): object {
    $payload=json_encode(['templateId'=>'910024','agreementType'=>'terms','locale'=>$locale,'sections'=>[['title'=>$title]]]);
    return (object)['resource_type'=>'agreement_template','resource_id'=>910024,'tenant_id'=>null,'site_id'=>null,
        'locale'=>$locale,'config_version'=>$version,'schema_version'=>1,'payload'=>$payload,'payload_sha256'=>hash('sha256',$payload),'previous_config_version'=>null];
};
$templateKey=$writer->templateKey('terms','en'); $cnKey=$writer->templateKey('terms','zh-CN');
$raw->del($templateKey,$cnKey);
$writer->publish($row(910024,'1'));
$writer->publish($templateRow('9007199254740993'));
$writer->publish($templateRow('9007199254740992','en','Old'));
$check($raw->hGet($templateKey,'config_version')==='9007199254740993');
$check($raw->hGet($templateKey,'template_id')==='910024' && $raw->hGet($templateKey,'locale')==='en');
$check($raw->hGet($templateKey,'site_id')===false && $raw->hLen($templateKey)===7);
$check($raw->hGet($key,'config_version')==='1');
$writer->publish($templateRow('1','zh-CN','中文'));
$check(json_decode($raw->hGet($cnKey,'payload'))->sections[0]->title==='中文');
try { $writer->publish($templateRow('9007199254740993','en','Conflict')); throw new LogicException('Template conflict ignored'); }
catch (RuntimeException $e) { $check(true); }
$check(json_decode($raw->hGet($templateKey,'payload'))->sections[0]->title==='Published');
$raw->del($key,$templateKey,$cnKey);
echo "Theme Redis Lua: {$checks} checks passed\n";
