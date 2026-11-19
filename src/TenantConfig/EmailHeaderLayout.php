<?php

declare(strict_types=1);

namespace SixMm\Shared\TenantConfig;

/** Managed preset header; uses only CSS supported by the sending service. */
final class EmailHeaderLayout
{
    private const OLD_HEADER = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse"><tr><td style="width:48px;vertical-align:middle"><img src="{{logoUrl}}" alt="Brand logo" width="36" height="36" style="display:block;border:0px solid #123b33"></td><td style="vertical-align:middle;color:#ffffff;font-size:24px;line-height:32px;font-weight:700">{{siteName}}</td><td style="text-align:right;vertical-align:middle;color:#a9d2c6;font-size:10px;line-height:16px;letter-spacing:1px">ACCOUNT<br>SECURITY</td></tr></table>';
    private const NAME_STYLE = 'padding-top:16px;color:#ffffff;font-size:20px;line-height:28px;font-weight:700';

    private static function nameCell(?string $name): string
    {
        if ($name === null || mb_strwidth($name, 'UTF-8') <= 20) {
            return '<td style="'.self::NAME_STYLE.'">{{siteName}}</td>';
        }
        // CSS overflow/white-space/text-overflow are not in User's allowlist.
        // Keep the full brand elsewhere; materialize only this managed heading.
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        return '<td style="'.self::NAME_STYLE.'" title="'.$escape($name).'">'
            .$escape(mb_strimwidth($name, 0, 20, '...', 'UTF-8')).'</td>';
    }

    public static function html(string $html, ?string $name = null, ?string $previousName = null): string
    {
        // A separate logo row prevents the name from competing for image space.
        // Only width is specified: landscape and square images retain their ratio.
        $header = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;table-layout:fixed;border-collapse:collapse"><tr><td style="vertical-align:middle"><img src="{{logoUrl}}" alt="Brand logo" width="96" style="display:block;width:96px;border:0px solid #123b33"></td><td style="text-align:right;vertical-align:middle;color:#a9d2c6;font-size:10px;line-height:16px;letter-spacing:1px">ACCOUNT<br>SECURITY</td></tr></table>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;table-layout:fixed;border-collapse:collapse"><tr>'.self::nameCell(null).'</tr></table>';
        $html = str_replace(self::OLD_HEADER, $header, $html);
        // Refresh a previously shortened heading when the brand is renamed.
        return str_replace(array_unique([self::nameCell(null), self::nameCell($previousName)]), self::nameCell($name), $html);
    }
}
