<?php

declare(strict_types=1);

namespace SixMm\Shared\ProductCategories;

interface ProductCategorySnapshotProvider
{
    public function snapshot(): ?ProductCategorySnapshot;
}
