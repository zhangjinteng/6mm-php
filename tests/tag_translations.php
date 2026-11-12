<?php

require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use SixMm\Shared\Cms\GoogleTranslationClient;
use SixMm\Shared\Cms\PlainTextTranslator;
use SixMm\Shared\SymbolTags\SymbolTagTranslations;
use SixMm\Shared\SymbolTags\SymbolTagTranslationException;

$app = new Container(); Facade::setFacadeApplication($app);
Http::swap(new Factory());
Http::fake(fn ($request) => $request['target'] === 'de'
    ? Http::response(['error' => 'secret-provider-error'], 403)
    : Http::response(['data' => ['translations' => [['translatedText' => 'Translated &amp; safe']]]], 200));
$capsule = new Capsule(); $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
foreach (['symbol_tag', 'agent_symbol_tag'] as $table) {
    $db->statement("CREATE TABLE $table (id integer PRIMARY KEY, home_site_id integer, agent_id integer, tag_name text, tag_name_zh text, tag_name_en text, updated_at text, deleted_at text)");
}
$db->statement('CREATE TABLE symbol_tag_translation (symbol_tag_id integer, agent_symbol_tag_id integer, locale text, name text, created_at text, updated_at text)');
foreach ([[1,0,0], [2,100,0], [3,100,7], [4,200,8]] as [$id,$site,$agent]) {
    $db->table('symbol_tag')->insert(['id'=>$id,'home_site_id'=>$site,'agent_id'=>$agent,'tag_name'=>'热门','tag_name_zh'=>'热门','tag_name_en'=>'Hot']);
}
$checks = 0;
$check = function ($condition) use (&$checks) { if (!$condition) throw new RuntimeException('Tag translations check failed #'.($checks+1)); $checks++; };
$runtimeCalls = [];
$service = new SymbolTagTranslations($db, function ($agent) use (&$runtimeCalls) { $runtimeCalls[] = $agent; return ['api_key'=>'FAKE-KEY','concurrency'=>2]; });
$platform = $service->forScope(0,0); $merchant = $service->forScope(100,7); $white = $service->forScope(100,0);
$check($platform->detail(0,1)['configured_count'] === 2);
$check(count($runtimeCalls) === 0); // Reads and manual saves never need a credential.
$platform->save(0,1,['zh-CN'=>'热榜','en'=>'Trending','ja'=>'人気']);
$white->save(0,2,['zh-CN'=>'白标','en'=>'White']);
$merchant->save(7,3,['zh-CN'=>'商户','en'=>'Merchant']);
$check($db->table('symbol_tag')->where('id',1)->value('tag_name_en') === 'Trending');
$check($db->table('symbol_tag_translation')->where('symbol_tag_id',1)->where('locale','ja')->value('name') === '人気');
$check(count($runtimeCalls) === 0);
foreach ([fn()=> $platform->detail(0,3), fn()=> $merchant->save(7,2,['en'=>'Forbidden']),
    fn()=> $white->translate(0,4,'zh-CN','秘密',['ja']), fn()=> $service->forScope(0,7),
    fn()=> $platform->save(0,1,['xx'=>'bad']), fn()=> $platform->save(0,1,['ja'=>['bad']])] as $invalid) {
    try { $invalid(); throw new RuntimeException('Invalid scope accepted'); }
    catch (SymbolTagTranslationException) { $check(true); }
}
$check(count($runtimeCalls) === 0);
$result = $merchant->translate(7,3,'zh-CN','热门',['ja','de']);
$check($result['translations']['ja'] === 'Translated & safe' && isset($result['failures']['de']));
$check(!str_contains(json_encode($result), 'secret-provider-error'));
$check($runtimeCalls === [7]);
$check($db->table('symbol_tag_translation')->where('symbol_tag_id',3)->where('locale','ja')->count() === 0);
$check(Http::recorded(fn ($request) => $request->hasHeader('X-Goog-Api-Key','FAKE-KEY') && $request['format'] === 'text')->count() === 2);
$fields = (new PlainTextTranslator((new GoogleTranslationClient())->withApiKey('FAKE-SEO')))->fields(
    ['title'=>'Title','description'=>'Description'], ['title'=>200,'description'=>1000], 'en','ja',10);
$check(count($fields['values']) === 2 && $fields['errors'] === []);
$fields = (new PlainTextTranslator((new GoogleTranslationClient())->withApiKey('FAKE-SEO')))->fields(['name'=>'Name'], ['name'=>1], 'en','ja',10);
$check(isset($fields['errors']['name']) && $fields['values'] === []);
echo "Tag translations: $checks checks passed\n";
