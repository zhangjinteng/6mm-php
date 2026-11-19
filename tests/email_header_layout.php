<?php

declare(strict_types=1);

require getenv('EMAIL_VALIDATOR_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';

use SixMm\Shared\TenantConfig\{EmailHeaderLayout, EmailTemplateRules};

$checks = 0;
$check = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Header layout check failed #'.($checks + 1));
    ++$checks;
};
foreach (EmailTemplateRules::defaults() as $template) {
    $doc = new DOMDocument(); @$doc->loadHTML('<?xml encoding="UTF-8">'.$template['htmlBody']);
    $logo = $doc->getElementsByTagName('img')->item(0);
    $check($logo->getAttribute('width') === '96' && !$logo->hasAttribute('height'));
    $check(!str_contains($logo->getAttribute('style'), 'height:'));
    $html = EmailHeaderLayout::html($template['htmlBody'], 'Freedex-self-operated');
    $check(str_contains($html, 'Freedex-self-oper...'));
    $check(str_contains($html, 'title="Freedex-self-operated"'));
    $check(str_contains($html, '{{siteName}}') && str_contains($html, '{{code}}'));
    $check(EmailHeaderLayout::html($html, 'Freedex-self-operated', 'Freedex-self-operated') === $html);
    $renamed = EmailHeaderLayout::html($html, 'Short', 'Freedex-self-operated');
    $check(!str_contains($renamed, 'Freedex-self-oper...') && !str_contains($renamed, 'title='));
    foreach (['测试品牌名称很长很长很长很长', 'A&B<'.str_repeat('X', 40), 'Short'] as $name) {
        $body = $template; $body['htmlBody'] = EmailHeaderLayout::html($template['htmlBody'], $name);
        EmailTemplateRules::template($body, ['cdn.example'], 'https://cdn.example/logo.svg');
        $check(!str_contains($body['htmlBody'], 'A&B<'));
    }
}
$custom = '<p style="color:#fff">{{siteName}} {{code}} {{expiresAt}}</p>';
$check(EmailHeaderLayout::html($custom, str_repeat('Long', 20)) === $custom);
echo "Email header layout: $checks checks passed\n";
