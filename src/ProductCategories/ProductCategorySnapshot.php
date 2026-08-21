<?php

declare(strict_types=1);

namespace SixMm\Shared\ProductCategories;

use Throwable;

final class ProductCategorySnapshot
{
    public const SCHEMA_VERSION = 1;

    /** @var array<string, ProductCategory> */
    private array $bySymbol;

    /** @var array<string, string[]> */
    private array $byCategory;

    /**
     * @param array<string, ProductCategory> $bySymbol
     */
    public function __construct(
        array $bySymbol,
        private string $dataVersion,
        private string $generatedAt,
        private int $schemaVersion = self::SCHEMA_VERSION
    ) {
        $normalized = [];
        foreach ($bySymbol as $symbol => $category) {
            if (!$category instanceof ProductCategory) {
                continue;
            }
            $normalizedSymbol = strtoupper(trim((string) $symbol));
            if ($normalizedSymbol !== '') {
                $normalized[$normalizedSymbol] = $category;
            }
        }
        ksort($normalized, SORT_STRING);
        $this->bySymbol = $normalized;
        $this->byCategory = $this->buildReverseIndex($normalized);
        $this->dataVersion = trim($this->dataVersion);
        $this->generatedAt = trim($this->generatedAt);
    }

    public static function tryFromArray(array $data): ?self
    {
        try {
            if ((int) ($data['schema_version'] ?? 0) !== self::SCHEMA_VERSION || !is_array($data['by_symbol'] ?? null)) {
                return null;
            }

            $categories = [];
            foreach ($data['by_symbol'] as $symbol => $categoryData) {
                if (!is_array($categoryData)) {
                    return null;
                }
                $normalizedSymbol = strtoupper(trim((string) $symbol));
                if ($normalizedSymbol === '') {
                    return null;
                }
                $categories[$normalizedSymbol] = ProductCategory::fromArray($categoryData);
            }

            $snapshot = new self(
                bySymbol: $categories,
                dataVersion: (string) ($data['data_version'] ?? ''),
                generatedAt: (string) ($data['generated_at'] ?? ''),
                schemaVersion: (int) $data['schema_version']
            );

            $suppliedHash = strtolower(trim((string) ($data['hash'] ?? '')));
            if ($suppliedHash !== '' && !hash_equals($snapshot->hash(), $suppliedHash)) {
                return null;
            }

            return $snapshot;
        } catch (Throwable) {
            return null;
        }
    }

    public function schemaVersion(): int { return $this->schemaVersion; }

    public function dataVersion(): string { return $this->dataVersion; }

    public function generatedAt(): string { return $this->generatedAt; }

    public function categoryForSymbol(string $symbol): ?ProductCategory
    {
        return $this->bySymbol[strtoupper(trim($symbol))] ?? null;
    }

    /** @return string[] */
    public function symbolsForCategory(string $categoryCode): array
    {
        return $this->byCategory[strtolower(trim($categoryCode))] ?? [];
    }

    /** @return array<string, ProductCategory> */
    public function categories(): array
    {
        $categories = [];
        foreach ($this->bySymbol as $category) {
            $categories[$category->code()] = $category;
        }
        ksort($categories, SORT_STRING);

        return $categories;
    }

    /** @return array<string, ProductCategory> */
    public function bySymbol(): array
    {
        return $this->bySymbol;
    }

    public function hash(): string
    {
        $payload = [];
        foreach ($this->bySymbol as $symbol => $category) {
            $payload[$symbol] = $category->toArray();
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function toArray(): array
    {
        $bySymbol = [];
        foreach ($this->bySymbol as $symbol => $category) {
            $bySymbol[$symbol] = $category->toArray();
        }

        return [
            'schema_version' => $this->schemaVersion,
            'data_version' => $this->dataVersion,
            'generated_at' => $this->generatedAt,
            'hash' => $this->hash(),
            'by_symbol' => $bySymbol,
            'by_category' => $this->byCategory,
        ];
    }

    /**
     * @param array<string, ProductCategory> $categories
     * @return array<string, string[]>
     */
    private function buildReverseIndex(array $categories): array
    {
        $byCategory = [];
        foreach ($categories as $symbol => $category) {
            $byCategory[$category->code()][] = $symbol;
        }
        ksort($byCategory, SORT_STRING);
        foreach ($byCategory as &$symbols) {
            sort($symbols, SORT_STRING);
        }
        unset($symbols);

        return $byCategory;
    }
}
