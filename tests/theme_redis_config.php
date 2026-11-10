<?php

declare(strict_types=1);

// Read host configuration with synthetic environment values only. No Kernel/.env or IO.
$host = getenv('THEME_REDIS_TEST_HOST');
$allowed = [dirname(__DIR__, 2).'/agent_admin', dirname(__DIR__, 3).'/platform/platform_admin'];
if (!in_array($host, $allowed, true)) throw new RuntimeException('Select one explicit local host source tree');
require $host.'/vendor/autoload.php';
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$suffixes = ['URL','SCHEME','HOST','PORT','PASSWORD','USERNAME','DB','CONNECT_TIMEOUT','READ_TIMEOUT'];
$keys = ['THEME_SNAPSHOT_REDIS_ENABLED','THEME_SNAPSHOT_REDIS_KEY_PREFIX','AGREEMENT_TEMPLATE_REDIS_KEY_PREFIX'];
foreach (['THEME_SNAPSHOT_REDIS','SHARED_REDIS','REDIS'] as $prefix) {
    foreach ($suffixes as $suffix) $keys[] = $prefix.'_'.$suffix;
}
$original = [];
foreach ($keys as $key) $original[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
$read = static function (array $values) use ($keys, $host): array {
    foreach ($keys as $key) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
    foreach ($values as $key=>$value) {
        putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = (string)$value;
    }
    return require $host.'/config/theme_snapshot_redis.php';
};
$checks = 0;
$check = static function (bool $ok) use (&$checks): void {
    ++$checks; if (!$ok) throw new RuntimeException('Redis configuration check failed #'.$checks);
};
try {
    $defaults = $read([]);
    $check($defaults['enabled'] === false);
    $check($defaults['key_prefix'] === 'wl:site:config:theme');
    $check($defaults['template_key_prefix'] === 'wl:config:agreement-template');
    $check($defaults['redis'] === ['url'=>null,'scheme'=>'tls','host'=>'127.0.0.1','password'=>null,
        'username'=>null,'port'=>6379,'database'=>0,'timeout'=>1.0,'read_timeout'=>2.0,'prefix'=>'','options'=>['prefix'=>'']]);

    $common = ['REDIS_SCHEME'=>'tcp','REDIS_HOST'=>'redis-default.invalid','REDIS_PORT'=>'6378',
        'REDIS_PASSWORD'=>'default-fixture','REDIS_USERNAME'=>'default-fixture-user','REDIS_DB'=>'4',
        'REDIS_CONNECT_TIMEOUT'=>'3.5','REDIS_READ_TIMEOUT'=>'4.5'];
    $legacy = ['THEME_SNAPSHOT_REDIS_URL'=>'redis://unused.invalid:9999/15',
        'THEME_SNAPSHOT_REDIS_SCHEME'=>'tls','THEME_SNAPSHOT_REDIS_HOST'=>'unused.invalid',
        'THEME_SNAPSHOT_REDIS_PORT'=>'9999','THEME_SNAPSHOT_REDIS_PASSWORD'=>'unused-fixture',
        'THEME_SNAPSHOT_REDIS_USERNAME'=>'unused-user','THEME_SNAPSHOT_REDIS_DB'=>'15',
        'THEME_SNAPSHOT_REDIS_CONNECT_TIMEOUT'=>'99','THEME_SNAPSHOT_REDIS_READ_TIMEOUT'=>'99'];
    $base = $read($common);
    $check($read($common + $legacy) === $base); // obsolete dedicated values cannot redirect writes
    foreach (['scheme'=>'tcp','host'=>'redis-default.invalid','port'=>'6378','password'=>'default-fixture',
        'username'=>'default-fixture-user','database'=>'4','timeout'=>3.5,'read_timeout'=>4.5] as $key=>$value) {
        $check($base['redis'][$key] === $value);
    }

    $shared = ['SHARED_REDIS_SCHEME'=>'tls','SHARED_REDIS_HOST'=>'redis-shared.invalid','SHARED_REDIS_PORT'=>'6380',
        'SHARED_REDIS_PASSWORD'=>'shared-fixture','SHARED_REDIS_USERNAME'=>'shared-fixture-user',
        'SHARED_REDIS_DB'=>'5','SHARED_REDIS_CONNECT_TIMEOUT'=>'5.5','SHARED_REDIS_READ_TIMEOUT'=>'6.5'];
    $preferred = $read($common + $shared + $legacy);
    foreach (['scheme'=>'tls','host'=>'redis-shared.invalid','port'=>'6380','password'=>'shared-fixture',
        'username'=>'shared-fixture-user','database'=>'5','timeout'=>5.5,'read_timeout'=>6.5] as $key=>$value) {
        $check($preferred['redis'][$key] === $value);
    }
    $check($preferred['redis']['prefix'] === '' && $preferred['redis']['options']['prefix'] === '');

    // Reuse existing connection URLs as well; never connect or print their contents.
    $urls = ['REDIS_URL'=>'redis://url-user:url-fixture@url-default.invalid:6381/6',
        'SHARED_REDIS_URL'=>'rediss://shared-url-user:shared-url-fixture@url-shared.invalid:6382/7'];
    $parser = new Illuminate\Support\ConfigurationUrlParser();
    $defaultUrl = $read($common + ['REDIS_URL'=>$urls['REDIS_URL']] + $legacy);
    $check($defaultUrl['redis']['url'] === $urls['REDIS_URL']);
    $parsed = $parser->parseConfiguration($defaultUrl['redis']);
    foreach (['host'=>'url-default.invalid','port'=>6381,'database'=>'6','username'=>'url-user','password'=>'url-fixture','driver'=>'tcp'] as $key=>$value) {
        $check($parsed[$key] === $value);
    }
    $sharedUrl = $read($common + $shared + $urls + $legacy);
    $check($sharedUrl['redis']['url'] === $urls['SHARED_REDIS_URL']);
    $parsed = $parser->parseConfiguration($sharedUrl['redis']);
    foreach (['host'=>'url-shared.invalid','port'=>6382,'database'=>'7','username'=>'shared-url-user','password'=>'shared-url-fixture','driver'=>'tls'] as $key=>$value) {
        $check($parsed[$key] === $value);
    }

    $custom = $read($common + ['THEME_SNAPSHOT_REDIS_ENABLED'=>'true','THEME_SNAPSHOT_REDIS_KEY_PREFIX'=>'custom:themes',
        'AGREEMENT_TEMPLATE_REDIS_KEY_PREFIX'=>'custom:templates']);
    $check($custom['enabled'] === true && $custom['key_prefix'] === 'custom:themes' && $custom['template_key_prefix'] === 'custom:templates');
    $check($custom['redis'] === $base['redis']);
    echo basename(dirname($host))." Redis configuration reuse: {$checks} checks passed\n";
} finally {
    foreach ($original as $key=>[$process, $env, $server]) {
        $process === false ? putenv($key) : putenv($key.'='.$process);
        if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
        if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
    }
}
