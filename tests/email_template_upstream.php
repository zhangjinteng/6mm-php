<?php

declare(strict_types=1);

// An installed consumer may point this script at its own autoloader to verify
// the exact dependency it runs, rather than the library source checkout.
require getenv('EMAIL_VALIDATOR_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

use SixMm\Shared\TenantConfig\EmailConfigException;
use SixMm\Shared\TenantConfig\EmailTemplateRules;
use SixMm\Shared\TenantConfig\EmailTemplateValidator;

$fixture = __DIR__.'/fixtures/upstream-email-validator/';
$provenance = json_decode(file_get_contents($fixture.'provenance.json'), true, 512, JSON_THROW_ON_ERROR);
if (hash_file('sha256', $fixture.'cases.jsonl') !== $provenance['cases_sha256']) throw new RuntimeException('Upstream fixtures were changed');
$core = file_get_contents((new ReflectionClass(EmailTemplateValidator::class))->getFileName());
$original = str_replace('namespace SixMm\\Shared\\TenantConfig;', 'namespace Freedex\\Email;', $core);
if (hash('sha256', $original) !== $provenance['validator_sha256']) throw new RuntimeException('Validator diverged from upstream beyond the namespace');
$passed = 0; $failures = [];
foreach (file($fixture.'cases.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $template = ['purpose' => 'login', 'locale' => 'en',
        'subject' => $case['subject'] ?? '{{siteName}} verification',
        'textBody' => $case['textBody'] ?? '{{code}} {{expiresAt}}', 'htmlBody' => $case['htmlBody']];
    $before = $template; $actual = true; $error = '';
    try {
        EmailTemplateRules::template($template, $case['hosts'] ?? ['cdn.example'],
            $case['logoUrl'] ?? 'https://cdn.example/logo.png', $case['rowStorage'] ?? true);
    } catch (EmailConfigException $e) { $actual = false; $error = $e->getMessage(); }
    if ($template !== $before) throw new RuntimeException('Validation rewrote '.$case['name']);
    if ($actual !== $case['valid']) $failures[] = $case['name'].': '.($actual ? 'accepted invalid input' : $error);
    else ++$passed;
}
if ($passed + count($failures) !== 155) throw new RuntimeException('Expected all 155 upstream cases');
if ($failures) throw new RuntimeException(implode("\n", $failures));
$upstreamPresets = [];
foreach (file($fixture.'cases.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with($case['name'], 'preset-')) continue;
    [$purpose, $locale] = explode('-', substr($case['name'], 7), 2);
    $upstreamPresets[$purpose.'/'.$locale] = ['purpose' => $purpose, 'locale' => $locale,
        'subject' => $case['subject'], 'textBody' => $case['textBody'], 'htmlBody' => $case['htmlBody']];
}
$assets = [];
foreach (json_decode(file_get_contents(dirname((new ReflectionClass(EmailTemplateRules::class))->getFileName()).'/presets/email-templates.json'), true, 512, JSON_THROW_ON_ERROR) as $asset) {
    $assets[$asset['purpose'].'/'.$asset['locale']] = $asset;
}
foreach (EmailTemplateRules::defaults() as $preset) {
    EmailTemplateRules::template($preset, ['cdn.example'], 'https://cdn.example/logo.png');
    $upstream = $upstreamPresets[$preset['purpose'].'/'.$preset['locale']] ?? null;
    if (!$upstream || ($assets[$preset['purpose'].'/'.$preset['locale']] ?? null) !== $upstream
        || array_diff_assoc(array_diff_key($preset, ['htmlBody' => true]), $upstream)) {
        throw new RuntimeException('Header adjustment changed upstream preset content');
    }
    unset($upstreamPresets[$preset['purpose'].'/'.$preset['locale']]);
}
if ($upstreamPresets) throw new RuntimeException('Missing upstream styled default');
echo "Upstream email validator: $passed/155 cases and 8 styled presets passed\n";
