<?php
declare(strict_types=1);
namespace SixMm\Shared\Prediction\Configuration;

/** Public geometry only; never computes probabilities or rewrites locked orders. */
final class GridLayoutInput
{
    public static function field(string $field, mixed $value, int $maxScale = 18): string|int
    {
        if ($field === 'price_decimals') {
            if (!is_int($value) || $value < 0 || $value > 8) {
                throw new GridConfigException('INVALID_LAYOUT_PRECISION', 'Price decimals must be an integer from 0 to 8');
            }
            return $value;
        }
        if (!is_string($value) || strlen($value)>128) { self::invalid(); }
        if ($field==='column_duration_seconds') {
            if (preg_match('/^[1-9][0-9]?$/D',$value)!==1 || (int)$value>25) { self::invalid(); }
            return $value;
        }
        if ($field!=='price_band_width' || preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value)!==1) { self::invalid(); }
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        if ($maxScale<18 && strlen($fraction)>$maxScale) {
            throw new GridConfigException('INVALID_LAYOUT_PRECISION','Grid price band width has too many decimal places');
        }
        $fraction=rtrim($fraction,'0');
        if (strlen($fraction)>18 || bccomp($value,'0',strlen($fraction))<=0) { self::invalid(); }
        return $whole.($fraction===''?'':'.'.$fraction);
    }

    public static function newDuration(mixed $value): string
    {
        $duration=self::field('column_duration_seconds',$value);
        if ((int)$duration>10) { self::invalid(); }
        return $duration;
    }

    public static function geometry(array $layout): void
    {
        foreach (['row_count','column_count'] as $key) {
            if (!isset($layout[$key]) || !is_string($layout[$key]) || preg_match('/^[1-9][0-9]{0,4}$/D',$layout[$key])!==1) { self::invalid(); }
        }
        $duration=(int)self::field('column_duration_seconds',$layout['column_duration_seconds']);
        $width = self::field('price_band_width',$layout['price_band_width']);
        if (isset($layout['price_decimals']) && strlen(explode('.', $width, 2)[1] ?? '') > $layout['price_decimals']) {
            throw new GridConfigException('INVALID_LAYOUT_PRECISION', 'Price band width must be exactly representable at the configured precision');
        }
        if ((int)$layout['row_count']*(int)$layout['column_count']>10000 || 5+((int)$layout['column_count']+1)*$duration>120) { self::invalid(); }
    }

    public static function priceBoundary(array $layout, string $markPrice): void
    {
        if (strlen($markPrice)>128 || preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$markPrice)!==1) {
            throw new GridConfigException('LAYOUT_PRICE_UNAVAILABLE','Current mark price is invalid');
        }
        $scale=max(18,strlen(explode('.',$markPrice,2)[1]??''));
        // A conservative full-height reserve guarantees positive boundaries even if the center shifts one row.
        $reserve=bcmul($layout['price_band_width'],(string)((int)$layout['row_count']+1),$scale);
        if (bccomp($markPrice,$reserve,$scale)<=0) { self::invalid(); }
    }

    public static function storedDecimals(mixed $value): ?int
    {
        if ($value === null) { return null; }
        if ((!is_int($value) && !is_string($value)) || preg_match('/^(0|[1-9]|1[0-8])$/D', (string)$value) !== 1) {
            throw new GridConfigException('DATA_INTEGRITY', 'Invalid stored layout price decimals');
        }
        return (int)$value;
    }

    private static function invalid(): never { throw new GridConfigException('INVALID_LAYOUT','Grid layout parameters are invalid'); }
}
