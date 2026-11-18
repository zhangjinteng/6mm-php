<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use SixMm\Shared\TenantConfig\EmailConfigException;
use SixMm\Shared\TenantConfig\EmailTemplateRules;

$checks = 0;
$check = static function (bool $valid, string $message) use (&$checks): void {
    if (!$valid) throw new RuntimeException($message);
    ++$checks;
};
$template = static fn (string $html): array => [
    'purpose' => 'login', 'locale' => 'en', 'subject' => '{{siteName}} - {{purposeLabel}}',
    'textBody' => 'Your code is {{code}}, valid for {{validMinutes}} minutes.', 'htmlBody' => $html,
];
$accept = static function (string $html) use ($template, $check): void {
    $body = $template($html === '' ? '' : $html.'<p>{{code}} {{validMinutes}}</p>');
    $before = $body;
    EmailTemplateRules::template($body, ['cdn.example']);
    $check($body === $before, 'Validation must preserve the original template bytes');
};
$reject = static function (string $html, string $message = '邮件 HTML 包含不允许的标签') use ($template, $check): void {
    try {
        EmailTemplateRules::template($template($html.'<p>{{code}} {{validMinutes}}</p>'), ['cdn.example']);
    } catch (EmailConfigException $e) {
        $check(str_contains($e->getMessage(), $message), 'Unexpected rejection: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Unsupported HTML was accepted: '.$html);
};

$defaults = EmailTemplateRules::defaults();
$check(count($defaults) === 8, 'All four purposes must have English and Chinese presets');
$identities = [];
foreach ($defaults as $body) {
    $identities[] = $body['purpose'].'/'.$body['locale'];
    $check(!preg_match('/<\/?(?:html|body|h1)\b/i', $body['htmlBody']), 'Presets must contain only supported HTML fragments');
    $check(str_contains($body['htmlBody'], '<p><strong>{{siteName}}</strong></p>'), 'Preset brand heading must use p/strong');
    $check(str_contains($body['htmlBody'], '<img src="{{logoUrl}}"'), 'Presets must retain the logo placeholder');
    EmailTemplateRules::template($body, ['cdn.example']);
    $check(true, 'Preset must pass publication validation');
}
$check(count(array_unique($identities)) === 8, 'Preset identities must be unique');

foreach (['', '<p>{{code}}</p>', '<P><STRONG>{{code}}</STRONG></P>',
    '<table role="presentation"><tr><td><img src="{{logoUrl}}" alt="" width="120"></td></tr></table>',
    '<p>&lt;html&gt; is displayed as text.</p>', '<p title="<html>">{{code}}</p>',
] as $html) $accept($html);

foreach (['<html><body><p>{{code}}</p></body></html>', '<body><p>{{code}}</p></body>',
    '<h1>{{siteName}}</h1>', '<HTML><BODY><H1>Title</H1></BODY></HTML>',
    '<p>{{code}}</p></html>', '<p>{{code}}</p><html lang="en">',
    '<script>alert(1)</script>', '<svg><title>x</title></svg>',
    '<h2>Title</h2>', '<ul><li>x</li></ul>', '<head></head>', '<style>p{color:red}</style>',
    '<!-- Comment -->', '<!DOCTYPE html>', '<custom>x</custom>',
] as $html) $reject($html);

foreach (['<img src="https://evil.example/a.png">' => '邮件图片必须使用',
    '<p onclick="alert(1)">x</p>' => '邮件 HTML 包含不允许的属性',
    '<p>{{logoUrl}}</p>' => 'Logo 变量',
] as $html => $message) $reject($html, $message);

// Attributes are checked by tag, not by a blacklist of known dangerous names.
foreach (['p','br','div','span','strong','b','em','i','table','tbody','tr','td','a','img'] as $tag) {
    $accept('<'.$tag.' title="Static" alt="" width="12" height="9999">');
}
foreach (['<table role="presentation" cellpadding="99" cellspacing="0" border="2" align="center"></table>',
    '<td align="right"></td>', '<a href="https://CDN.EXAMPLE/help?a=1&amp;b=2">Help</a>',
    '<img src="https://cdn.example/logo.png">',
] as $html) $accept($html);
foreach (['class="x"','id="x"','target="_blank"','colspan="2"','rowspan="2"','data-x="1"',
    'role="presentation"','align="left"','width="12px"','height="10000"','title="{{code}}"',
    'alt="{{siteName}}"','href="https://cdn.example/x"','src="https://cdn.example/x"',
] as $attr) $reject('<p '.$attr.'>x</p>', '邮件 HTML');
foreach (['<table role="Presentation"></table>', '<table border="100"></table>', '<table align="LEFT"></table>',
    '<td align="justify"></td>', '<img src="&#123;&#123;logoUrl&#125;&#125;">',
    '<p>&#123;&#123;logoUrl&#125;&#125;</p>', '<td border="1">x</td>', '<img align="left">',
    '<img src="{{ logoUrl }}">', '<img src="{{logoUrl}}/logo.png">',
] as $html) $reject($html, '邮件');
foreach (['https://cdn.example:443/x','https://cdn.example:/x','https://u:pw@cdn.example/x',
    'http://cdn.example/x','https://evil.example/x','https://cdn.example.evil.test/x','https://sub.cdn.example/x',
    '', 'https://cdn.example/x\\bad',
] as $url) {
    $reject('<a href="'.$url.'">Help</a>', 'HTTPS');
    $reject('<img src="'.$url.'">', 'HTTPS');
}
EmailTemplateRules::url('https://CDN.EXAMPLE/logo.png', ['CdN.ExAmPlE']);
$check(true, 'Resource host comparison must be case insensitive');
foreach (['https://cdn.example:443/x','https://cdn.example:/x','https://u@cdn.example/x'] as $url) {
    try { EmailTemplateRules::url($url, ['cdn.example']); throw new RuntimeException('Invalid logo URL accepted'); }
    catch (EmailConfigException $e) { $check(true, 'Invalid logo URL rejected'); }
}

$styles = [
    'color:#aBc', 'background-color:#AABBCC', 'color:transparent',
    'font-family:Arial, Helvetica, sans-serif', 'font-family:Courier New, monospace',
    'font-size:8px', 'font-size:64px', 'line-height:10px', 'line-height:96px',
    'font-weight:400', 'font-weight:500', 'font-weight:600', 'font-weight:700',
    'text-align:left', 'text-align:center', 'text-align:right',
    'vertical-align:top', 'vertical-align:middle', 'vertical-align:bottom',
    'text-decoration:none', 'text-decoration:underline', 'display:block', 'display:inline-block',
    'border-collapse:collapse', 'border-collapse:separate', 'table-layout:fixed', 'border-spacing:0',
    'width:100%', 'width:1px', 'max-width:1024px', 'height:1px', 'height:256px',
    'letter-spacing:0', 'letter-spacing:8px', 'border-radius:0', 'border-radius:32px',
    'padding:0 1px 64px 0px', 'margin:64px', 'margin:0 0', 'padding:0 0 0', 'border:0 solid #fff',
];
foreach (['top','right','bottom','left'] as $side) {
    $styles[] = 'padding-'.$side.':64px';
    $styles[] = 'margin-'.$side.':0';
    $styles[] = 'border-'.$side.':4px solid #123456';
}
foreach ($styles as $style) $accept('<p style="'.$style.'">Text</p>');
foreach (['color:red', 'color:#abcd', 'background-color:TRANSPARENT', 'font-family:arial, Helvetica, sans-serif',
    'font-family:Arial,Helvetica,sans-serif', 'font-size:7px', 'font-size:65px', 'font-size:8.5px',
    'font-size:12PX', 'font-size:0', 'line-height:9px', 'line-height:97px', 'font-weight:bold',
    'text-align:justify', 'vertical-align:baseline', 'text-decoration:overline', 'display:inline',
    'border-collapse:initial', 'table-layout:auto', 'border-spacing:1px', 'width:99%', 'width:0',
    'width:1025px', 'max-width:0px', 'height:257px', 'height:0', 'letter-spacing:9px',
    'border-radius:33px', 'padding:65px', 'padding:0 0 0 0 0', 'margin:-1px', 'margin:1em',
    'padding-inline:1px', 'margin-block:0', 'border:5px solid #fff', 'border:1px dashed #fff',
    'border:1px solid transparent', 'border-color:#fff', 'border-inline:1px solid #fff',
    'Color:#fff', 'color:#fff!important', 'width:calc(100% - 1px)', 'color:var(--brand)',
    'background:url(https://cdn.example/a)', 'position:absolute', 'color:#fff;color:#000',
    'color:#fff;COLOR:#000', 'color',
] as $style) $reject('<p style="'.$style.'">Text</p>', 'CSS');
$accept('<p style="'.implode(';', array_slice($styles, 0, 1)).';">Text</p>');
$uniqueStyles = ['color:#fff','background-color:#000','font-family:Arial, Helvetica, sans-serif',
    'font-size:12px','line-height:20px','font-weight:400','text-align:left','vertical-align:top',
    'text-decoration:none','display:block','border-collapse:collapse','table-layout:fixed',
    'border-spacing:0','width:100%','max-width:1024px','height:120px','letter-spacing:0',
    'border-radius:0','padding:0','margin:0','padding-top:0','padding-right:0','padding-bottom:0','padding-left:0'];
$accept('<p style="'.implode(';', $uniqueStyles).'">Text</p>');
$reject('<p style="'.implode(';', $uniqueStyles).';margin-top:0">Text</p>', 'CSS');
$reject('<p style="color:'.str_repeat(' ', 2048).'#fff">Text</p>', 'CSS');

$rejectBody = static function (array $patch) use ($template, $check): void {
    try {
        EmailTemplateRules::template(array_replace($template('<p>{{code}} {{expiresAt}}</p>'), $patch), ['cdn.example']);
    } catch (EmailConfigException $e) { $check(true, 'Invalid template rejected'); return; }
    throw new RuntimeException('Invalid template accepted: '.json_encode($patch));
};
foreach ([['textBody'=>'{{code}}'], ['textBody'=>'{{validMinutes}}'], ['htmlBody'=>'<p>{{code}}</p>'],
    ['htmlBody'=>'<p>{{expiresAt}}</p>'], ['textBody'=>'{{unknown}} {{code}} {{expiresAt}}'],
    ['htmlBody'=>'<p>{{unknown}} {{code}} {{expiresAt}}</p>'], ['textBody'=>'{{logoUrl}} {{code}} {{expiresAt}}'],
    ['textBody'=>"{{code}} {{expiresAt}} \xff"], ['htmlBody'=>"<p>{{code}} {{expiresAt}} \xff</p>"],
    ['subject'=>"Title\t"], ['subject'=>"Title\u{0085}"], ['subject'=>"\xff"],
    ['subject'=>str_repeat('中',201)], ['textBody'=>str_repeat('x',8193)], ['htmlBody'=>str_repeat('x',32769)],
] as $patch) $rejectBody($patch);
$accept('<p>{{code}} {{expiresAt}}</p>');
$accept("<img src='{{logoUrl}}'>");
// Byte limits and Unicode subject length are inclusive, not character-based
// body limits or a limit on the expanded mail content.
$valid = $template('<p>{{code}} {{expiresAt}}</p>');
foreach (['subject' => str_repeat('中', 200),
    'textBody' => str_pad('{{code}} {{expiresAt}}', 8192, 'x'),
    'htmlBody' => str_pad('<p>{{code}} {{expiresAt}}</p>', 32768, 'x'),
] as $field => $value) {
    EmailTemplateRules::template(array_replace($valid, [$field => $value]), ['cdn.example']);
    $check(true, 'Exact size limit accepted');
}
$accept('<p style="color:'.str_repeat(' ', 2038).'#fff">Text</p>');
foreach (['<p title=test>Text</p>', '<p title="a" title="b">Text</p>',
    '<p>A & B</p>', '<p>1 < 2</p>', '<p title>Text</p>',
    '<p>&#123&#123logoUrl&#125&#125</p>', "<p>\0Text</p>",
    '<p style="padding:000001px">Text</p>',
] as $html) $reject($html, '邮件');
$accept('<p style="border:1px  solid  #fff">Text</p>');
$accept('<p style="' . "\u{00a0}color:#fff\u{00a0}" . '">Text</p>');
$rejectBody(['subject' => "\u{3000}\u{00a0}"]);
$rejectBody(['htmlBody' => null]);
EmailTemplateRules::template($valid, ['::1'], 'https://[::1]/logo.png');
$check(true, 'IPv6 host uses the upstream unbracketed allowlist convention');
echo "Email template rules checks: $checks passed\n";
