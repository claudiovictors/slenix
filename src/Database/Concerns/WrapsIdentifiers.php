<?php

/*
|--------------------------------------------------------------------------
| WrapsIdentifiers — Slenix Framework
|--------------------------------------------------------------------------
|
| Quotes table and column names using the right character for the active
| PDO driver: backticks for MySQL / MariaDB, double quotes for PostgreSQL
| and SQLite. Used by QueryBuilder and Model, so no SQL is built with a
| hard-coded backtick anymore.
|
| Place at: src/Database/Concerns/WrapsIdentifiers.php
|
*/

declare(strict_types=1);

namespace Slenix\Database\Concerns;

use PDO;

trait WrapsIdentifiers
{
    /**
     * Quotes an identifier for the current database driver.
     *
     * Handles dotted names (`users.id` -> "users"."id") and keeps `*` as is.
     * Anything that is not a plain identifier (expressions, function calls,
     * aliases such as `name AS n`, or names that are already quoted) is
     * returned untouched. Because only [A-Za-z0-9_.*] ever gets quoted, the
     * result can never contain an injected quote character.
     *
     * @param  string $name Column, table or `table.column` name.
     * @return string       Quoted identifier.
     */
    protected function wrap(string $name): string
    {
        $name = trim($name);

        if ($name === '' || !preg_match('/^[\w.*]+$/', $name)) {
            return $name;
        }

        $quote = $this->identifierQuote();

        return implode('.', array_map(
            static fn(string $part): string => $part === '*' ? $part : $quote . $part . $quote,
            explode('.', $name)
        ));
    }

    /**
     * Returns the identifier quote character for the current PDO driver.
     *
     * @return string '`' for MySQL / MariaDB, '"' for everything else
     *                (PostgreSQL, SQLite, ...).
     */
    protected function identifierQuote(): string
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return in_array($driver, ['mysql', 'mariadb'], true) ? '`' : '"';
    }
}
