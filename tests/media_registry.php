<?php

declare(strict_types=1);

// Laravel helpers come from the host, but always exercise the shared source checkout.
require dirname(__DIR__, 2).'/agent_admin/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'SixMm\\Shared\\';
    if (str_starts_with($class, $prefix)) require dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
}, true, true);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use SixMm\Shared\Media\{MediaRegistry, MediaUploadException};

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Repository(['imgbed' => ['instance'=>'test-bed', 'token'=>'test-token',
    'base_url'=>'https://bed.test', 'public_base'=>'https://cdn.test', 'channel'=>'test-channel']]));
$capsule = new Capsule($app);
$capsule->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>''], 'pgsql');
$app->instance('db', $capsule->getDatabaseManager());
$db = $capsule->getConnection('pgsql');
$db->statement("ATTACH DATABASE ':memory:' AS cms");
$db->statement('CREATE TABLE cms.media_files (id integer primary key, owner_type text, owner_id int, imgbed_instance text, channel_name text, file_id text, src text, public_url text, original_name text, mime_type text, size_bytes int, width int, height int, sha256 text, uploaded_by int, storage_status text, unreferenced_since text, tracking_state text, UNIQUE(imgbed_instance,file_id))');
$db->statement('CREATE TABLE cms.media_upload_grants (media_id int, owner_type text, owner_id int, UNIQUE(media_id,owner_type,owner_id))');
$db->getPdo()->sqliteCreateFunction('hashtextextended', fn () => 1, 2);
$db->getPdo()->sqliteCreateFunction('pg_advisory_xact_lock', fn () => 1, 1);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC9sAAAAASUVORK5CYII=');
$hash = md5($png);
$key = "media/upload_files/$hash.png";
$file = fn () => UploadedFile::fake()->createWithContent('cover.png', $png);
$checks = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    if (!$ok) throw new RuntimeException($why);
    ++$checks;
};
$reset = static function () use ($app, $db): void {
    $db->table('cms.media_upload_grants')->delete();
    $db->table('cms.media_files')->delete();
    Facade::clearResolvedInstance(Factory::class);
    $app->instance(Factory::class, new Factory());
};
$reply = fn (string $path) => [['src'=>'/file/'.$path, 'publicUrl'=>'https://cdn.test/'.$path]];
$fake = static fn (array $responses) => Http::fake(static function ($request) use ($responses) {
    foreach ($responses as $pattern => $reply) {
        if (Illuminate\Support\Str::is($pattern, $request->url())) return is_callable($reply) ? $reply($request) : $reply;
    }
    throw new LogicException('Unexpected HTTP request; directory lookup is forbidden');
});
$registry = new MediaRegistry();

$reset();
$fake(['https://bed.test/upload*'=>Http::response($reply($key))]);
$result = $registry->upload($file(), 4, 10);
$check($result['url'] === 'https://cdn.test/'.$key, 'Returns direct upload URL');
$check($db->table('cms.media_files')->value('tracking_state') === 'tracked', 'Fresh upload is tracked');
Http::assertSentCount(1);
Http::assertSent(fn ($r) => $r->method() === 'POST' && str_starts_with($r->url(), 'https://bed.test/upload?') && $r->hasHeader('Authorization', 'Bearer test-token'));
$check($registry->upload($file(), 5, 10) === $result, 'Same-owner database hit reuses file');
$check($registry->upload($file(), 6, 20) === $result, 'Identical content can be granted, not reassigned');
$check((int)$db->table('cms.media_files')->value('owner_id') === 10 && $db->table('cms.media_upload_grants')->where('owner_id',20)->exists(), 'Ownership and reuse grant preserved');
Http::assertSentCount(1);

foreach (['(1)', '(23)', '%281%29'] as $suffix) {
    $reset();
    $actual = "media/upload_files/$hash$suffix.png";
    $fake(['https://bed.test/upload*'=>Http::response($reply($actual))]);
    $uploaded = $registry->upload($file(), 4, 10);
    $check($uploaded['url'] === 'https://cdn.test/'.$actual, 'Returns actual renamed URL');
    $check($db->table('cms.media_files')->value('file_id') === rawurldecode($actual), 'Physical file key stored');
    $check($registry->upload($file(), 5, 10) === $uploaded, 'Renamed file deduplicated by SHA-256');
    Http::assertSentCount(1);
}

$expectFailure = static function (string $code, int $status, callable $upload) use ($check, $db): void {
    try { $upload(); throw new LogicException('Expected upload failure'); }
    catch (MediaUploadException $e) {
        $response = $e->response();
        $body = $response->getData(true);
        $check($response->getStatusCode() === $status && $body['data']['error_code'] === $code && $body['show'] === 1, 'Typed safe error envelope');
        $check(!str_contains($response->getContent(), 'private-token') && !str_contains($response->getContent(), '<html>'), 'No upstream body leaked');
    }
    $check($db->table('cms.media_files')->count() === 0, 'Failed upload not registered');
};
foreach ([401,403,429,500] as $status) {
    $reset();
    $fake(['https://bed.test/upload*'=>Http::response('<html>private-token</html>', $status)]);
    $expectFailure(in_array($status,[401,403],true) ? 'MEDIA_STORAGE_REJECTED' : 'MEDIA_STORAGE_FAILED', 502, fn () => $registry->upload($file(),4,10));
    Http::assertSentCount(1);
}
$reset();
$fake(['https://bed.test/upload*'=>static fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 28: private-token timeout')]);
$expectFailure('MEDIA_STORAGE_UNAVAILABLE',504,fn () => $registry->upload($file(),4,10));

foreach ([
    $reply('media/upload_files/other.png'), $reply("media/upload_files/$hash(0).png"),
    $reply("elsewhere/$hash.png"), $reply("media/upload_files/../$hash.png"),
    [['src'=>'/file/'.$key, 'publicUrl'=>'https://evil.test/'.$key]],
    [['src'=>'/file/'.$key, 'publicUrl'=>'https://cdn.test/'.$key.'?secret=private-token']],
    ['error'=>'private-token'], [],
] as $bad) {
    $reset();
    $fake(['https://bed.test/upload*'=>Http::response($bad)]);
    $expectFailure('MEDIA_STORAGE_INVALID_RESPONSE',502,fn () => $registry->upload($file(),4,10));
}
$reset();
$fake(['https://bed.test/upload*'=>Http::response('<html>private-token</html>')]);
$expectFailure('MEDIA_STORAGE_INVALID_RESPONSE',502,fn () => $registry->upload($file(),4,10));

$reset();
$db->statement("CREATE TRIGGER cms.deny_registration BEFORE INSERT ON media_files BEGIN SELECT RAISE(ABORT,'private-token'); END");
$fake(['https://bed.test/upload*'=>Http::response($reply($key))]);
$expectFailure('MEDIA_REGISTRATION_FAILED',503,fn () => $registry->upload($file(),4,10));
$db->statement('DROP TRIGGER cms.deny_registration');
Http::assertSentCount(1);
// The uncommitted first copy is untouched; retry registers the bed's newly renamed copy.
Facade::clearResolvedInstance(Factory::class);
$app->instance(Factory::class, new Factory());
$renamed = "media/upload_files/$hash(1).png";
$fake(['https://bed.test/upload*'=>Http::response($reply($renamed))]);
$retried = $registry->upload($file(),4,10);
$check($retried['url'] === 'https://cdn.test/'.$renamed, 'Registration failure retry uses actual new copy');
$check($registry->upload($file(),4,10) === $retried, 'Retry result reused by content');
Http::assertSentCount(1);

$reset();
$fake(['https://bed.test/upload*'=>static fn () => throw new Illuminate\Http\Client\ConnectionException('Connection refused')]);
$expectFailure('MEDIA_STORAGE_UNAVAILABLE',502,fn () => $registry->upload($file(),4,10));
$reset();
$app['config']->set('imgbed.token', '');
$fake([]);
$expectFailure('MEDIA_STORAGE_NOT_CONFIGURED',503,fn () => $registry->upload($file(),4,10));
Http::assertNothingSent();
$app['config']->set('imgbed.token', 'test-token');

echo "Media direct upload: $checks checks passed\n";
