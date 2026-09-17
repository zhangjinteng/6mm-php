<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use SixMm\Shared\TranslationConfig\TranslationConfigCipher;
use SixMm\Shared\TranslationConfig\TranslationConfigService;
use SixMm\Shared\TranslationConfig\TranslationConfigVerifier;
use SixMm\Shared\TranslationConfig\TranslationVerificationResult;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertTranslationConfigSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$database = new Capsule();
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$database->setAsGlobal();
$connection = $database->getConnection();
$schema = $connection->getSchemaBuilder();
$schema->create('agent_translation_config', static function (Blueprint $table): void {
    $table->unsignedBigInteger('agent_id')->primary();
    $table->string('provider', 20)->default('google');
    $table->string('api_version', 10)->default('v2');
    $table->text('api_key_ciphertext');
    $table->string('api_key_mask', 32)->nullable();
    $table->unsignedSmallInteger('concurrency')->default(3);
    $table->boolean('is_enabled')->default(true);
    $table->string('verify_status', 20)->default('pending');
    $table->dateTime('verified_at')->nullable();
    $table->text('last_error')->nullable();
    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->dateTime('created_at');
    $table->dateTime('updated_at');
});

$cipher = new class implements TranslationConfigCipher {
    public function encrypt(string $value): string { return base64_encode($value); }
    public function decrypt(string $value): string { return (string) base64_decode($value, true); }
};
$verifier = new class implements TranslationConfigVerifier {
    public string $receivedKey = '';
    public function verify(string $apiKey): TranslationVerificationResult
    {
        $this->receivedKey = $apiKey;
        return TranslationVerificationResult::valid();
    }
};
$service = new TranslationConfigService($connection, $cipher, $verifier);

$saved = $service->save(10, 21, [
    'api_version' => 'v2',
    'api_key' => 'AIzaExampleSecret1234',
    'concurrency' => 5,
    'is_enabled' => true,
]);
assertTranslationConfigSame('AIza****1234', $saved['api_key_mask'], 'The shared service must mask API keys.');
assertTranslationConfigSame(true, $saved['api_key_set'], 'The shared service must report a configured API key.');
assertTranslationConfigSame(21, (int) $connection->table('agent_translation_config')->value('created_by'), 'The actor must be recorded.');

$tested = $service->test(10);
assertTranslationConfigSame(true, $tested['valid'], 'The saved API key should be verified.');
assertTranslationConfigSame('AIzaExampleSecret1234', $verifier->receivedKey, 'The decrypted key must reach the verifier.');
assertTranslationConfigSame('valid', $connection->table('agent_translation_config')->value('verify_status'), 'Saved-key verification must update status.');

$schema->drop('agent_translation_config');
$candidateResult = $service->test(10, 'candidate-key');
assertTranslationConfigSame(true, $candidateResult['valid'], 'Candidate-key verification must not require the configuration table.');
assertTranslationConfigSame('candidate-key', $verifier->receivedKey, 'The candidate key must be verified directly.');

echo "Translation configuration shared service tests passed.\n";
