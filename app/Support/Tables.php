<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Which tables/columns exist in the current company's database. Companies were set up at different
 * times, so an optional module's tables (payroll, wages, projects ...) may simply not be there.
 * Answers are cached per database for the request.
 */
class Tables
{
    public static function has(string $table, ?string $column = null): bool
    {
        static $cache = [];
        $db = config('database.connections.tenant.database');
        $key = "$db.$table.".($column ?? '');

        return $cache[$key] ??= $column === null
            ? Schema::connection('tenant')->hasTable($table)
            : Schema::connection('tenant')->hasColumn($table, $column);
    }
}
