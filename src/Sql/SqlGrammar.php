<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Sql;

use Hypervel\Database\BinaryParameter;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\PostgresGrammar;

/**
 * Compiles Hypervel's query builder into the SQL InfluxDB 3 runs.
 *
 * InfluxDB 3 plans SQL with Apache DataFusion, whose dialect follows
 * PostgreSQL's: double-quoted identifiers, a doubled `'` as the only escape
 * in a string, the `~` family of regular-expression operators, ILIKE, `::`
 * casts, extract(), DISTINCT ON, joins, unions and window functions. So this
 * is Hypervel's PostgreSQL grammar, with what DataFusion reads differently
 * changed:
 *
 * - exists() selects a row only when one exists, since DataFusion cannot
 *   select the value of an EXISTS;
 * - a lock compiles to nothing, as there is no transaction to hold one, and
 *   there is no count of open connections to ask for;
 * - a date is written as RFC3339 with its own offset;
 * - the values are embedded in the statement, so the placeholders are found
 *   the way DataFusion reads the statement.
 *
 * What DataFusion lacks, such as JSON operators and full-text search, still
 * compiles as PostgreSQL writes it, and the server refuses it.
 */
class SqlGrammar extends PostgresGrammar
{
    /**
     * Compile an exists statement into SQL.
     *
     * DataFusion plans EXISTS in a where clause but not in a select list,
     * so the statement returns one row when the query has any, and none
     * otherwise, which the builder's exists() reads as false.
     */
    public function compileExists(Builder $query): string
    {
        $select = $this->compileSelectQuery($query);

        return $this->compileSelectTimeout(
            $query,
            "select true as {$this->wrap('exists')} where exists({$select})",
        );
    }

    /**
     * Compile the lock into SQL.
     *
     * InfluxDB 3 has no transactions for a lock to last in, so
     * lockForUpdate() and sharedLock() compile to nothing, as they do for
     * SQLite. A lock given as a string is still written as given.
     */
    protected function compileLock(Builder $query, bool|string $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Compile a query to get the number of open connections for a database.
     *
     * InfluxDB 3 serves SQL over stateless HTTP requests, so there is no
     * count to ask for.
     */
    public function compileThreadCount(): ?string
    {
        return null;
    }

    /**
     * Get the format for database stored dates.
     *
     * RFC3339 with microseconds and the date's own offset, which DataFusion
     * reads as the instant it names, whatever the date's time zone.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d\TH:i:s.uP';
    }

    /**
     * Substitute the given bindings into the given raw SQL query.
     *
     * The connection sends the statement this returns, so every placeholder
     * is found the way DataFusion reads the statement: a `?` counts outside
     * string literals, quoted identifiers and comments; a doubled quote
     * escapes itself in a literal or an identifier; a backslash escapes a
     * character only in an `E'...'` string; and a doubled `??` is a literal
     * question mark, as it is for PDO. The values are embedded escaped by the
     * connection and are not read again.
     *
     * @param array<mixed> $bindings
     */
    public function substituteBindingsIntoRawSql(string $sql, array $bindings): string
    {
        $bindings = array_map(function (mixed $value): string {
            if ($value instanceof BinaryParameter) {
                return $this->escape($value->value, true);
            }

            if (is_resource($value) || gettype($value) === 'resource (closed)') {
                $value = (string) $value;
            }

            return $this->escape($value);
        }, array_values($bindings));

        $query = '';
        $index = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === "'" || $char === '"') {
                // Copy the literal or identifier whole, up to its unescaped closing quote.
                $escapes = $char === "'" && $this->startsEscapeString($sql, $i);
                $end = $i + 1;

                while ($end < $length) {
                    if ($escapes && $sql[$end] === '\\') {
                        $end += 2;
                    } elseif ($sql[$end] === $char && ($sql[$end + 1] ?? '') === $char) {
                        $end += 2;
                    } elseif ($sql[$end] === $char) {
                        break;
                    } else {
                        ++$end;
                    }
                }

                $query .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '-' && $next === '-') {
                // Copy a line comment up to its end of line.
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;

                $query .= substr($sql, $i, $end - $i);
                $i = $end - 1;
            } elseif ($char === '/' && $next === '*') {
                // Copy a block comment up to its end.
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;

                $query .= substr($sql, $i, $end - $i);
                $i = $end - 1;
            } elseif ($char === '?' && $next === '?') {
                $query .= '?';
                ++$i;
            } elseif ($char === '?') {
                $query .= $bindings[$index++] ?? '?';
            } else {
                $query .= $char;
            }
        }

        return $query;
    }

    /**
     * Determine if the quote at the given offset opens an `E'...'` string, whose backslashes escape.
     *
     * It does when a lone `E` or `e` comes right before it, rather than the
     * last letter of a longer word.
     */
    protected function startsEscapeString(string $sql, int $offset): bool
    {
        return $offset > 0
            && ($sql[$offset - 1] === 'E' || $sql[$offset - 1] === 'e')
            && ($offset === 1 || preg_match('/[\w$]/', $sql[$offset - 2]) !== 1);
    }
}
