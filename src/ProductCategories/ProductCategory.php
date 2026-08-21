<?php

declare(strict_types=1);

namespace SixMm\Shared\ProductCategories;

use InvalidArgumentException;

final class ProductCategory
{
    public function __construct(
        private string $code,
        private string $nameZh = '',
        private string $nameEn = '',
        private ?int $tagId = null,
        private bool $active = true
    ) {
        $this->code = strtolower(trim($this->code));
        $this->nameZh = trim($this->nameZh);
        $this->nameEn = trim($this->nameEn);

        if ($this->code === '') {
            throw new InvalidArgumentException('Product category code cannot be empty.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) ($data['code'] ?? ''),
            nameZh: (string) ($data['name_zh'] ?? ''),
            nameEn: (string) ($data['name_en'] ?? ''),
            tagId: isset($data['tag_id']) ? (int) $data['tag_id'] : null,
            active: !array_key_exists('active', $data) || (bool) $data['active']
        );
    }

    public function code(): string { return $this->code; }

    public function nameZh(): string { return $this->nameZh; }

    public function nameEn(): string { return $this->nameEn; }

    public function tagId(): ?int { return $this->tagId; }

    public function active(): bool { return $this->active; }

    public function withActive(bool $active): self
    {
        return new self($this->code, $this->nameZh, $this->nameEn, $this->tagId, $active);
    }

    public function displayName(string $locale = 'zh-CN'): string
    {
        if (str_starts_with(strtolower($locale), 'zh')) {
            return $this->nameZh !== '' ? $this->nameZh : ($this->nameEn !== '' ? $this->nameEn : $this->code);
        }

        return $this->nameEn !== '' ? $this->nameEn : ($this->nameZh !== '' ? $this->nameZh : $this->code);
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name_zh' => $this->nameZh,
            'name_en' => $this->nameEn,
            'tag_id' => $this->tagId,
            'active' => $this->active,
        ];
    }
}
