<?php

declare(strict_types=1);

namespace SixMm\Shared\ProductCategories;

final class ProductCategoryResolver
{
    public function __construct(private ProductCategorySnapshotProvider $provider)
    {
    }

    public function categoryForSymbol(string $symbol): ?ProductCategory
    {
        return $this->provider->snapshot()?->categoryForSymbol($symbol);
    }

    /** @return array<string, ProductCategory> */
    public function categoriesForSymbols(array $symbols): array
    {
        $snapshot = $this->provider->snapshot();
        if ($snapshot === null) {
            return [];
        }

        $result = [];
        foreach ($symbols as $symbol) {
            $normalized = strtoupper(trim((string) $symbol));
            if ($normalized === '') {
                continue;
            }
            $category = $snapshot->categoryForSymbol($normalized);
            if ($category !== null) {
                $result[$normalized] = $category;
            }
        }

        return $result;
    }

    /** @return string[] */
    public function symbolsForCategory(string $categoryCode): array
    {
        return $this->provider->snapshot()?->symbolsForCategory($categoryCode) ?? [];
    }

    /** @return array<string, ProductCategory> */
    public function categories(): array
    {
        return $this->provider->snapshot()?->categories() ?? [];
    }

    public function appendToRows(
        array &$rows,
        string $symbolKey = 'symbol',
        string $categoryCodeKey = 'product_category',
        string $categoryNameKey = 'product_category_name',
        string $locale = 'zh-CN'
    ): void {
        if ($rows === []) {
            return;
        }

        $symbols = array_values(array_unique(array_filter(array_map(
            static fn ($row): string => strtoupper(trim((string) ($row[$symbolKey] ?? ''))),
            $rows
        ))));
        $categories = $this->categoriesForSymbols($symbols);

        foreach ($rows as &$row) {
            $symbol = strtoupper(trim((string) ($row[$symbolKey] ?? '')));
            $category = $categories[$symbol] ?? null;
            $row[$categoryCodeKey] = $category?->code();
            $row[$categoryNameKey] = $category?->displayName($locale);
        }
        unset($row);
    }
}
