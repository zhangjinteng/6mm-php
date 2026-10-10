<?php

declare(strict_types=1);

namespace SixMm\Shared\FuturesReports;

use InvalidArgumentException;

final class SchemaNames
{
    public static function searchPath(string $value): array
    {
        $schemas = array_values(array_unique(array_map('trim', explode(',', $value))));
        foreach ($schemas as $schema) {
            if (!in_array($schema, ['public', 'control_plane'], true)) {
                throw new InvalidArgumentException('DB_SCHEMA 仅支持 public、control_plane');
            }
        }
        return $schemas;
    }
}
