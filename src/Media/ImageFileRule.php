<?php

declare(strict_types=1);

namespace SixMm\Shared\Media;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class ImageFileRule implements Rule
{
    private string $error = '仅支持有效的 JPG / PNG / WebP / SVG / ICO 图片，最大 5 MB';

    public function passes($attribute, $value): bool
    {
        if (!$value instanceof UploadedFile) return false;
        try { ImageFile::uploaded($value); return true; }
        catch (ValidationException $e) { $this->error = collect($e->errors())->flatten()->first(); return false; }
    }

    public function message(): string { return $this->error; }
}
