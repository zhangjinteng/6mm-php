<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/fixtures/translation_settings.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\Factory;
use SixMm\Shared\Cms\{TranslationCredential, TranslationSettingsStore};
use SixMm\Shared\TranslationConfig\{LegacyMerchantTranslationMigration, MerchantTranslationSettings, TranslationConfigCipher, TranslationConfigService, TranslationConfigVerifier, TranslationVerificationResult};

if (!function_exists('config')) {
    function config($key, $default = null) { return $GLOBALS['translationTestConfig'][$key] ?? $default; }
}
$app = new Container(); Container::setInstance($app); Facade::setFacadeApplication($app);
$GLOBALS['translationTestConfig'] = ['cms_translation.encryption_key'=>'base64:'.base64_encode(str_repeat('s',32)),
    'cms_translation.credential_ref'=>'fixture', 'cms_translation.google_api_key'=>'ENV-KEY-MUST-NOT-BE-BORROWED'];
$app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $app));
$capsule = new Capsule(); $capsule->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$db = $capsule->getConnection(); translationSettingsFixture($db);
$checks = 0;
$check = function ($ok) use (&$checks): void { ++$checks; if (!$ok) throw new RuntimeException('Translation settings check failed #'.$checks); };
$cipher = new class implements TranslationConfigCipher {
    public function encrypt(string $value): string { return base64_encode($value); }
    public function decrypt(string $value): string { $v=base64_decode($value,true); if ($v===false) throw new RuntimeException('bad legacy key'); return $v; }
};
$verifier = new class implements TranslationConfigVerifier {
    public string $receivedKey = '';
    public function verify(string $apiKey): TranslationVerificationResult { $this->receivedKey=$apiKey; return TranslationVerificationResult::valid(); }
};
$service = new TranslationConfigService($db,$cipher,$verifier);
$saved = $service->save(10,21,['api_key'=>'FAKE-SECRET-1234','concurrency'=>5,'is_enabled'=>true]);
$check($saved['api_key_mask']==='••••1234' && $saved['api_key_set']);
$row = $db->table('cms.translation_settings')->where('config_key','merchant:10')->first();
$check((int) $row->created_by_id===21 && $row->created_by_type==='agent');
$check((new TranslationCredential())->resolve($row)==='FAKE-SECRET-1234');
$check($service->test(10)['valid'] && $verifier->receivedKey==='FAKE-SECRET-1234');
$check($db->table('cms.translation_settings')->value('verify_status')==='valid');
$merchant = new MerchantTranslationSettings($db);
$check(!$merchant->settings(11)['credential_configured']);
$check($merchant->runtime(10)['concurrency']===5);
$store = new TranslationSettingsStore($db);
$p = ['space_revision'=>'1','api_key'=>'','target_locales'=>['ja'],'concurrency_limit'=>8,
    'is_enabled'=>true,'request_timeout_seconds'=>19,'max_attempts'=>2];
$values = $store->prepare($store->row('merchant:10'),$p,'zh-CN');
$store->persist('merchant:10',$values,1,['type'=>'agent','id'=>22]);
$check($merchant->runtime(10)['target_locales']===['ja'] && $merchant->runtime(10)['timeout']===19);
try { $store->persist('merchant:10',$values,1,['type'=>'agent','id'=>22]); throw new RuntimeException('stale accepted'); }
catch (\Illuminate\Validation\ValidationException) { $check($merchant->settings(10)['space_revision']==='2'); }
$check($service->test(11,'candidate-key')['valid'] && $verifier->receivedKey==='candidate-key');

// Legacy schema may lack all newly added optional fields. Migration must preserve it too.
$db->statement('CREATE TABLE agent_translation_config (agent_id integer PRIMARY KEY, provider text, api_version text, api_key_ciphertext text, concurrency integer, is_enabled boolean, verify_status text, verified_at text, last_error text, created_by integer, updated_by integer, created_at text, updated_at text)');
$old = ['agent_id'=>20,'provider'=>'google','api_version'=>'v2','api_key_ciphertext'=>$cipher->encrypt('OLD-KEY-1234'),
    'concurrency'=>4,'is_enabled'=>true,'verify_status'=>'valid','created_by'=>7,'updated_by'=>8,'created_at'=>'2026-11-01','updated_at'=>'2026-11-02'];
$db->table('agent_translation_config')->insert($old);
$db->table('agent_translation_config')->insert(array_replace($old,['agent_id'=>21,'api_key_ciphertext'=>'not a ciphertext!']));
try { LegacyMerchantTranslationMigration::run($db,$cipher); throw new RuntimeException('bad key accepted'); }
catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'21') && !str_contains($e->getMessage(),'OLD-KEY')); }
$check($db->getSchemaBuilder()->hasTable('agent_translation_config') && $store->row('merchant:20')===null);
$db->table('agent_translation_config')->where('agent_id',21)->delete();
$check(LegacyMerchantTranslationMigration::run($db,$cipher)===1);
$check(!$db->getSchemaBuilder()->hasTable('agent_translation_config'));
$migrated = $store->row('merchant:20');
$check((new TranslationCredential())->resolve($migrated)==='OLD-KEY-1234' && $migrated->api_key_ciphertext!==$old['api_key_ciphertext']);
$check($merchant->runtime(20)['concurrency']===4 && count($merchant->runtime(20)['target_locales'])===19);
$check((int) $migrated->updated_by_id===8 && $migrated->created_at==='2026-11-01');
$check(LegacyMerchantTranslationMigration::run($db,$cipher)===0);
$check($merchant->runtime(10)['target_locales']===['ja']);

// No legacy storage exists now: old HTTP adapter, new settings and execution still share one row.
$service->save(20,9,['api_key'=>'','concurrency'=>6,'is_enabled'=>true]);
$check($merchant->runtime(20)['concurrency']===6 && $merchant->settings(20)['space_revision']==='1');
echo "Unified translation settings: $checks checks passed.\n";
