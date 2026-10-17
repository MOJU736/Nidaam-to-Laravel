<?php

namespace App\Services\Settings;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings an older company database up to the layout the app expects (database/migrations/tenant): tables that are missing
 * are created and columns that are missing are added, with the company's table prefix. Nothing is dropped, renamed or
 * changed, and no row is touched. It reads the migration files for the wanted layout, so there is no second list to keep up to date.
 *
 * Indexes of tables that already exist are not added (only their missing columns are).
 */
class TenantUpgrade
{
    /** @return array<string, Closure> table => the closure that defines it, in migration order */
    protected function wanted(): array
    {
        $found = [];
        $recorder = new class($found)
        {
            public function __construct(public array &$found) {}

            public function create(string $table, Closure $define): void
            {
                $this->found[$table] = $define;
            }

            public function __call(string $name, array $args)
            {
                return null; // dropIfExists and the like are not wanted here
            }
        };

        Schema::swap($recorder);
        try {
            $files = glob(database_path('migrations/tenant/*.php')) ?: [];
            sort($files);
            foreach ($files as $file) {
                $migration = require $file;
                if (is_object($migration)) {
                    $migration->up();
                }
            }
        } finally {
            app()->forgetInstance('db.schema');
            Schema::clearResolvedInstance('db.schema');
        }

        return $found;
    }

    /**
     * What is missing in this company database.
     *
     * @return array<int, array{action: string, table: string, columns: string[]}>
     */
    public function plan(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $plan = [];
        foreach ($this->wanted() as $table => $define) {
            if (! $schema->hasTable($table)) {
                $bp = new Blueprint($db, $table);
                $define($bp);
                $plan[] = ['action' => 'create', 'table' => $table, 'columns' => array_map(fn ($c) => (string) $c->name, $bp->getColumns())];
                continue;
            }
            $have = array_map('strtolower', $schema->getColumnListing($table));
            $bp = new Blueprint($db, $table);
            $define($bp);
            $missing = array_values(array_filter($bp->getColumns(), fn ($c) => ! in_array(strtolower((string) $c->name), $have, true)));
            if ($missing) {
                $plan[] = ['action' => 'add', 'table' => $table, 'columns' => array_map(fn ($c) => (string) $c->name, $missing)];
            }
        }

        return $plan;
    }

    /** Carries out a plan made by plan(). Returns the number of tables created and the number of columns added. */
    public function apply(Connection $db, array $plan): array
    {
        $schema = $db->getSchemaBuilder();
        $wanted = $this->wanted();
        $created = 0;
        $added = 0;
        foreach ($plan as $step) {
            $table = $step['table'];
            $define = $wanted[$table];
            if ($step['action'] === 'create') {
                $schema->create($table, $define);
                $created++;
                if ($table === 'payment_methods') { // the list every other company starts with, so the payment forms are not empty
                    foreach (['Bank Transfer', 'Card', 'Cash', 'Cheque', 'EVC Plus'] as $name) {
                        $db->table('payment_methods')->insert(['name' => $name, 'created_at' => now()]);
                    }
                }
                continue;
            }
            $bp = new Blueprint($db, $table);
            $define($bp);
            $defs = array_filter($bp->getColumns(), fn ($c) => in_array((string) $c->name, $step['columns'], true));
            $schema->table($table, function (Blueprint $t) use ($defs) {
                foreach ($defs as $c) {
                    $t->addColumn($c->type, $c->name, array_diff_key($c->getAttributes(), ['type' => 1, 'name' => 1]));
                }
            });
            $added += count($defs);
        }

        return [$created, $added];
    }
}
