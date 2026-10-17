<?php

namespace App\Database;

use Illuminate\Database\MySqlConnection;

/**
 * A MySQL connection that also puts the company's table prefix on table names written inside raw SQL.
 *
 * Laravel adds the prefix (for example "chetravel_") to table names it builds itself, but not to a name written inside
 * selectRaw(), whereRaw() and similar. Companies that share one database with others use a prefix, so a raw
 * "entryitems.amount" must become "chetravel_entryitems.amount". Companies without a prefix are not touched at all.
 */
class PrefixAwareConnection extends MySqlConnection
{
    /** @var array<string, string[]> the unprefixed table names of each prefix in each database */
    protected static array $tables = [];

    protected function run($query, $bindings, \Closure $callback)
    {
        if ($this->tablePrefix !== '') {
            $query = $this->prefixRaw($query);
        }

        return parent::run($query, $bindings, $callback);
    }

    /** The names (without the prefix) of this company's tables. */
    protected function ownTables(): array
    {
        $key = $this->getDatabaseName().'|'.$this->tablePrefix;
        if (! isset(self::$tables[$key])) {
            $like = addcslashes($this->tablePrefix, '_%').'%';
            $pdo = $this->getPdo();
            $stmt = $pdo->query('SHOW TABLES LIKE '.$pdo->quote($like));
            $len = strlen($this->tablePrefix);
            $names = array_map(fn ($n) => substr($n, $len), $stmt->fetchAll(\PDO::FETCH_COLUMN));
            usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
            self::$tables[$key] = $names;
        }

        return self::$tables[$key];
    }

    /** "entryitems.amount" and "FROM entryitems" that are not yet prefixed get the prefix. */
    public function prefixRaw(string $sql): string
    {
        $names = $this->ownTables();
        if (! $names) {
            return $sql;
        }
        $alt = implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names));
        $p = $this->tablePrefix;
        // a column written as table.column, not part of a longer word and not already quoted or prefixed
        $sql = preg_replace('/(?<![\w`.$])('.$alt.')\.(?=[\w*`])/', $p.'$1.', $sql);
        // a table written after FROM or JOIN in a raw sub-query
        $sql = preg_replace('/\b(FROM|JOIN)(\s+)('.$alt.')\b(?![`\w.])/i', '$1$2'.$p.'$3', $sql);

        // the same, with the table name in backticks
        $sql = preg_replace('/\b(FROM|JOIN|INTO|UPDATE)(\s+)`('.$alt.')`/i', '$1$2`'.$p.'$3`', $sql);

        // Laravel puts the prefix on a table's nickname too ("invoice_items as ii" becomes "... as chetravel_ii"), so a raw "ii.amount" needs it as well
        if (preg_match_all('/\bas\s+`'.preg_quote($p, '/').'(\w+)`/i', $sql, $m)) {
            foreach (array_unique($m[1]) as $alias) {
                $sql = preg_replace('/(?<![\w`.$])'.preg_quote($alias, '/').'\.(?=[\w*`])/', $p.$alias.'.', $sql);
            }
        }

        return $sql;
    }
}
