<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use SixMm\Shared\Media\ImageFile;

$app = new Container();
$factory = new Factory(new Translator(new ArrayLoader(), 'en'), $app);
$app->instance('validator', $factory); Facade::setFacadeApplication($app);
$checks = 0;
$check = static function (bool $ok) use (&$checks): void { if (!$ok) throw new RuntimeException('Image check failed #'.($checks+1)); ++$checks; };
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC9sAAAAASUVORK5CYII=');
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="paint"><stop offset="0" stop-color="#fff"/></linearGradient></defs><path style="fill:url(#paint);stroke-width:1" d="M0 0h24v24z"/></svg>';
$pngIco = pack('vvv',0,1,1).pack('CCCCvvVV',1,1,0,0,1,32,strlen($png),22).$png;
$dib = pack('VVVvvVVVVVV',40,2,4,1,32,0,16,0,0,0,0).str_repeat("\0",16);
$dibIco = pack('vvv',0,1,1).pack('CCCCvvVV',2,2,0,0,1,32,strlen($dib),22).$dib;
$files = ['png'=>[$png,1,1], 'svg'=>[$svg,24,24], 'ico'=>[$pngIco,1,1]];
foreach ($files as $ext=>[$bytes,$w,$h]) {
    $info = ImageFile::inspect($bytes);
    $check($info['extension'] === $ext && $info['width'] === $w && $info['height'] === $h);
    $check(!$factory->make(['file'=>UploadedFile::fake()->createWithContent('image.'.$ext,$bytes)], ['file'=>ImageFile::rules()])->fails());
}
$check(ImageFile::inspect($dibIco)['width'] === 2);
$check(ImageFile::inspect(str_pad($pngIco, ImageFile::MAX_BYTES, "\0"))['extension'] === 'ico');
$check(ImageFile::inspect(str_pad($svg, ImageFile::MAX_BYTES, ' '))['extension'] === 'svg');
foreach (['jpeg','webp'] as $format) {
    $img = imagecreatetruecolor(2,3); ob_start(); ('image'.$format)($img); $bytes = ob_get_clean();
    $check(ImageFile::inspect($bytes)['mime'] === 'image/'.$format);
}
$check(ImageFile::inspect('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><rect width="32" height="32" fill="red"/></svg>')['width'] === 32);
$check(ImageFile::inspect('<svg xmlns="http://www.w3.org/2000/svg"><defs><path id="a" d="M0 0h1"/></defs><use href="#a"/></svg>')['extension'] === 'svg');
foreach ([
    '<script>alert(1)</script>', '<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>',
    '<path onload="alert(1)"/>', '<style>@import url(https://bad.test/style.css);</style>',
    '<path style="fill:url(https://bad.test/x.svg)"/>', '<path style="fill:u\\72l(https://bad.test/x.svg)"/>',
    '<path style="behavior:url(x)"/>', '<use href="javascript:alert(1)"/>', '<use href="data:image/svg+xml,test"/>',
    '<image href="https://bad.test/img.png"/>', '<animate attributeName="href" values="javascript:alert(1)"/>',
    '<g id="loop"><use href="#loop"/></g>', '<path xmlns:evil="https://bad.test" evil:x="x"/>',
    '<?xml-stylesheet href="https://bad.test/x.css"?>', '<path style="fill:var(--evil)"/>',
] as $danger) {
    try { ImageFile::inspect('<svg xmlns="http://www.w3.org/2000/svg">'.$danger.'</svg>'); throw new RuntimeException('Unsafe SVG accepted: '.$danger); }
    catch (ValidationException $e) { $check(true); }
}
foreach ([
    '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg" width="8193" height="1"/>', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0"/>',
    '<svg xmlns="http://www.w3.org/2000/svg" width="1000in" height="10"/>', '<svg xmlns="http://www.w3.org/2000/svg" width="-1" height="10"/>',
    '<svg xmlns="https://bad.test"/>', '<svg xmlns="http://www.w3.org/2000/svg">', '<html><script>evil</script></html>',
    'fake image', str_repeat('x',ImageFile::MAX_BYTES+1), substr($pngIco,0,-1), substr($dibIco,0,-1),
    pack('vvv',0,1,256),
] as $bad) {
    try { ImageFile::inspect($bad); throw new RuntimeException('Invalid image accepted'); }
    catch (ValidationException $e) { $check(true); }
}
$check($factory->make(['file'=>UploadedFile::fake()->create('large.svg',5121,'image/svg+xml')], ['file'=>ImageFile::rules()])->fails());
echo "Media image policy: {$checks} checks passed\n";
