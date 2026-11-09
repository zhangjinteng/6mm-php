<?php

declare(strict_types=1);

namespace SixMm\Shared\Media;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** One byte-level policy for uploads and private feedback previews. Never trusts the browser MIME. */
final class ImageFile
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const LABEL = 'JPG / PNG / WebP / SVG / ICO，最大 5 MB';

    public static function rules(bool $required = true): array
    {
        return ['bail', $required ? 'required' : 'nullable', 'file', 'max:5120', new ImageFileRule()];
    }

    public static function uploaded(UploadedFile $file): array
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) self::fail('请选择不超过 5 MB 的有效图片');
        $bytes = file_get_contents($file->getPathname());
        if ($bytes === false) self::fail('图片读取失败');
        return ['bytes' => $bytes] + self::inspect($bytes);
    }

    private static function fail(string $message = '仅支持有效的 JPG / PNG / WebP / SVG / ICO 图片'): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    public static function inspect(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) self::fail('请选择不超过 5 MB 的有效图片');
        if (str_starts_with($bytes, "\0\0\1\0")) return self::ico($bytes);
        $size = @getimagesizefromstring($bytes);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$size['mime'] ?? ''] ?? null;
        if ($extension) {
            if (min($size[0], $size[1]) < 1 || max($size[0], $size[1]) > 8192) self::fail('图片宽高不能超过 8192 像素');
            return ['extension' => $extension, 'mime' => $size['mime'], 'width' => $size[0], 'height' => $size[1]];
        }
        return self::svg($bytes);
    }

    private static function svg(string $bytes): array
    {
        // Never enable DTD loading, entity substitution, XInclude or external network resolution.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) self::fail('SVG 不允许包含 DTD 或实体声明');
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            if (!$doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS) || $doc->doctype) self::fail();
            $root = $doc->documentElement;
            if (!$root || $root->localName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') self::fail();
            $allowedTags = array_flip(['svg','g','defs','path','rect','circle','ellipse','line','polyline','polygon','text','tspan',
                'title','desc','linearGradient','radialGradient','stop','clipPath','mask','pattern','symbol','use']);
            $allowedAttributes = array_flip(explode(' ', 'id class version width height viewBox preserveAspectRatio x y x1 y1 x2 y2 cx cy r rx ry d points dx dy rotate textLength lengthAdjust transform fill fill-rule fill-opacity stroke stroke-width stroke-linecap stroke-linejoin stroke-miterlimit stroke-dasharray stroke-dashoffset stroke-opacity opacity color color-interpolation vector-effect paint-order clip-path clip-rule clipPathUnits mask maskUnits maskContentUnits gradientUnits gradientTransform spreadMethod offset stop-color stop-opacity fx fy fr patternUnits patternContentUnits patternTransform font-family font-size font-weight font-style text-anchor dominant-baseline alignment-baseline letter-spacing word-spacing visibility display overflow role focusable style href'));
            $nodes = 0;
            $walk = function (DOMNode $node, int $depth) use (&$walk, &$nodes, $allowedTags, $allowedAttributes): void {
                if (++$nodes > 10000 || $depth > 64) self::fail('SVG 结构过于复杂');
                if ($node instanceof DOMElement) {
                    if ($node->namespaceURI !== 'http://www.w3.org/2000/svg' || !isset($allowedTags[$node->localName])) self::fail('SVG 含不支持的动态或嵌入内容');
                    foreach ($node->attributes as $attr) {
                        $name = $attr->localName;
                        if ($attr->namespaceURI === 'http://www.w3.org/2000/xmlns/') continue;
                        if ($attr->namespaceURI && !($attr->namespaceURI === 'http://www.w3.org/1999/xlink' && $name === 'href')
                            && !($attr->namespaceURI === 'http://www.w3.org/XML/1998/namespace' && $name === 'space')) self::fail('SVG 属性命名空间不安全');
                        if ($name === 'space' && $attr->namespaceURI === 'http://www.w3.org/XML/1998/namespace') continue;
                        if (!isset($allowedAttributes[$name]) && !preg_match('/^aria-[a-z-]+$/D', $name)) self::fail('SVG 含不支持的属性');
                        $value = trim($attr->value);
                        if ($name === 'href' && !preg_match('/^#[a-zA-Z_][a-zA-Z0-9_.:-]*$/D', $value)) self::fail('SVG 不允许外部或内嵌资源引用');
                        if ($name === 'style') {
                            foreach (explode(';', $value) as $declaration) {
                                if (trim($declaration) === '') continue;
                                if (!preg_match('/^\s*([a-z-]+)\s*:\s*(.+)$/D', $declaration, $style)
                                    || !in_array($style[1], ['fill','fill-rule','fill-opacity','stroke','stroke-width','stroke-linecap','stroke-linejoin',
                                        'stroke-miterlimit','stroke-dasharray','stroke-dashoffset','stroke-opacity','opacity','color','stop-color','stop-opacity',
                                        'font-size','font-family','font-weight','font-style','text-anchor','display','visibility','clip-path','clip-rule'], true)) self::fail('SVG 样式含不支持的声明');
                                self::safeValue($style[2]);
                            }
                        } else self::safeValue($value);
                    }
                    if ($node->localName === 'use') {
                        $href = $node->getAttribute('href') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                        $target = (new \DOMXPath($node->ownerDocument))->query('//*[@id="'.substr($href, 1).'"]')->item(0);
                        if (!$target || $target->localName === 'use' || (new \DOMXPath($node->ownerDocument))->query('.//*[local-name()="use"]', $target)->length) self::fail('SVG 引用无效或存在递归引用');
                    }
                } elseif (!in_array($node->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE, XML_COMMENT_NODE, XML_DOCUMENT_NODE], true)) {
                    self::fail('SVG 不允许处理指令或实体');
                }
                foreach ($node->childNodes as $child) $walk($child, $depth + 1);
            };
            $walk($doc, 0);
            $box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
            $view = count($box) === 4 && count(array_filter($box, 'is_numeric')) === 4 ? [abs((float)$box[2]), abs((float)$box[3])] : [300,150];
            $dimension = static function (string $raw, float $fallback): int {
                $raw = trim($raw);
                if ($raw === '' || preg_match('/^[0-9]+(?:\.[0-9]+)?%$/D', $raw)) $value = $fallback;
                elseif (preg_match('/^([0-9]+(?:\.[0-9]+)?)(px|pt|pc|mm|cm|in)?$/D', $raw, $m)) {
                    $value = (float)$m[1] * ([''=>1, 'px'=>1, 'pt'=>96/72, 'pc'=>16, 'mm'=>96/25.4, 'cm'=>96/2.54, 'in'=>96][$m[2] ?? '']);
                } else self::fail('SVG 宽高格式不支持，请使用像素或 viewBox');
                if (!is_finite($value) || $value <= 0 || $value > 8192) self::fail('SVG 宽高必须在 1–8192 像素内');
                return max(1, (int)ceil($value));
            };
            return ['extension'=>'svg', 'mime'=>'image/svg+xml', 'width'=>$dimension($root->getAttribute('width'), $view[0]),
                'height'=>$dimension($root->getAttribute('height'), $view[1])];
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private static function safeValue(string $value): void
    {
        // No CSS escapes/comments, scripts, external URLs, imports, data URIs or CSS variables.
        $plain = preg_replace('/url\(\s*#[a-zA-Z_][a-zA-Z0-9_.:-]*\s*\)/i', 'local-reference', $value);
        if (preg_match('~[\\\\<>@]|/\*|\*/|(?:url|expression|var)\s*\(|(?:javascript|vbscript|data)\s*:~i', $plain)
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $plain)) self::fail('SVG 含危险样式或资源引用');
    }

    private static function ico(string $bytes): array
    {
        if (strlen($bytes) < 22) self::fail('ICO 文件不完整');
        $count = unpack('v', substr($bytes, 4, 2))[1];
        $end = 6 + $count * 16;
        if ($count < 1 || $count > 256 || strlen($bytes) < $end) self::fail('ICO 图片目录无效');
        $width = $height = 0;
        for ($i=0; $i<$count; ++$i) {
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', substr($bytes, 6+$i*16, 16));
            $w = $entry['width'] ?: 256; $h = $entry['height'] ?: 256;
            if ($entry['reserved'] !== 0 || $entry['size'] < 8 || $entry['offset'] < $end
                || $entry['offset'] + $entry['size'] > strlen($bytes)) self::fail('ICO 图像数据无效');
            $image = substr($bytes, $entry['offset'], $entry['size']);
            if (str_starts_with($image, "\x89PNG\r\n\x1a\n")) {
                $size = @getimagesizefromstring($image);
                if (!$size || $size[0] !== $w || $size[1] !== $h || $size['mime'] !== 'image/png') self::fail('ICO 内嵌 PNG 无效');
            } else {
                if (strlen($image) < 40) self::fail('ICO 位图不完整');
                $dib = unpack('Vheader/Vwidth/Vheight/vplanes/vbits/Vcompression/VimageSize/Vx/Vy/Vcolors/Vimportant', substr($image,0,40));
                if (!in_array($dib['header'], [40,52,56,108,124], true) || $dib['header'] > strlen($image)
                    || $dib['width'] !== $w || $dib['height'] !== 2*$h || $dib['planes'] !== 1
                    || !in_array($dib['bits'], [1,4,8,16,24,32], true) || !in_array($dib['compression'], [0,3], true)) self::fail('ICO 位图格式无效');
                $palette = $dib['bits'] <= 8 ? ($dib['colors'] ?: 1 << $dib['bits']) * 4 : 0;
                $masks = $dib['compression'] === 3 && $dib['header'] === 40 ? 12 : 0;
                $pixels = (int)(ceil($w*$dib['bits']/32)*4*$h);
                $andMask = $dib['bits'] === 32 ? 0 : (int)(ceil($w/32)*4*$h);
                if ($dib['header']+$palette+$masks+$pixels+$andMask > strlen($image)) self::fail('ICO 像素数据不完整');
            }
            $width = max($width,$w); $height = max($height,$h);
        }
        return ['extension'=>'ico', 'mime'=>'image/vnd.microsoft.icon', 'width'=>$width, 'height'=>$height];
    }
}
