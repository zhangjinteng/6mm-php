<?php

namespace SixMm\Shared\FuturesReports\Legacy\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared facts always belong to public, regardless of a worker's search_path. */
abstract class PublicModel extends Model
{
    public function getTable()
    {
        $table = parent::getTable();
        return $this->getConnection()->getDriverName() === 'pgsql' && !str_contains($table, '.')
            ? 'public.' . $table : $table;
    }
}
