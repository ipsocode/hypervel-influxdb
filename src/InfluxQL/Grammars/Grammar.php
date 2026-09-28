<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars;

use DateTimeInterface;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\InfluxQL\Dialect;
use Ipsocode\InfluxDB\InfluxQL\Expression;
use Ipsocode\InfluxDB\InfluxQL\Grammars\Concerns\RefusesWhatTheVersionLacks;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Version;
use RuntimeException;

/**
 * Compiles a Builder into InfluxQL, as InfluxDB 1.x defines the language.
 *
 * Shaped like Hypervel\Database\Query\Grammars\Grammar: a select statement is
 * assembled component by component, each `where` type has its own small
 * compiler, values are compiled to `?` placeholders and embedded only when the
 * statement is sent. There is one subclass per InfluxDB version, as Hypervel
 * has one per database. Each names its Version, and a statement that version
 * does not run is refused as it compiles, before anything is sent
 * (RefusesWhatTheVersionLacks). How identifiers and literals are written is
 * the Dialect's, which the grammar delegates to. What differs from SQL is the
 * dialect itself:
 *
 * - identifiers are double-quoted and escape `"` with a backslash, string
 *   literals are single-quoted and escape `'` the same way — not doubled as
 *   in SQL — and a literal newline in either must be written `\n`;
 * - there is no `IN`, `BETWEEN`, `NOT`, `NULL` or `LIKE`: `whereIn` and
 *   `whereBetween` are expanded into the `=`/`OR` and `>=`/`AND` comparisons
 *   they stand for, and a null value is refused rather than sent;
 * - a regular expression is its own literal, `/pattern/`, so it travels as a
 *   Regex binding rather than a quoted string;
 * - a column name is quoted whole. Only a measurement is split on dots, into
 *   the `"db"."rp"."measurement"` form InfluxQL gives FROM.
 */
abstract class Grammar
{
    use RefusesWhatTheVersionLacks;

    /**
     * The comparison operators InfluxQL's WHERE clause accepts.
     *
     * @var list<string>
     */
    protected array $operators = Dialect::OPERATORS;

    /**
     * The components that make up a select clause, in the order InfluxQL wants them.
     *
     * @var list<string>
     */
    protected array $selectComponents = [
        'aggregate',
        'columns',
        'into',
        'from',
        'wheres',
        'groups',
        'fill',
        'orders',
        'limit',
        'offset',
        'slimit',
        'soffset',
        'timezone',
    ];

    /**
     * Compile a select query into InfluxQL.
     */
    public function compileSelect(Builder $query): string
    {
        // If the query does not have any columns set, we'll set the columns to the
        // * character to just get all of the fields from the measurement. Then we
        // can build the query and concatenate all the pieces together as one.
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        $sql = trim($this->concatenate($this->compileComponents($query)));

        $query->columns = $original;

        return $sql;
    }

    /**
     * Compile a delete statement into InfluxQL.
     *
     * InfluxQL's DELETE takes a measurement and a WHERE on time and tags only;
     * the select clauses of the builder are ignored, as they are in SQL. The
     * measurement cannot be qualified: InfluxDB's parser refuses a database or
     * retention policy in a DELETE, which applies to the database the request
     * addresses. A version that cannot run the statement on the connection
     * refuses it first, whatever the measurement: InfluxDB 3 has no DELETE,
     * and 2.x runs one on the database's default retention policy only.
     *
     * @throws InvalidArgumentException when the builder has no measurement, or a qualified one
     * @throws RuntimeException when the connection's version cannot run the statement
     */
    public function compileDelete(Builder $query): string
    {
        $this->ensureVersionCompilesDelete($this->version(), $query->getConnection()->getRetentionPolicy());

        $this->ensureDeleteTakesABareMeasurement($query->from);

        return trim('DELETE FROM ' . $this->wrapMeasurement($query->from) . ' ' . $this->compileWheres($query));
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @return array<string, null|string>
     */
    protected function compileComponents(Builder $query): array
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            if (isset($query->{$component})) {
                $method = 'compile' . ucfirst($component);

                $sql[$component] = $this->{$method}($query, $query->{$component});
            }
        }

        return $sql;
    }

    /**
     * Compile an aggregated select clause.
     *
     * The result column is aliased `aggregate`, as Hypervel does, so the
     * builder can read it back by name. A wildcard is left unaliased: InfluxQL
     * expands `COUNT(*)` to one column per field, and an alias on that is an
     * error.
     *
     * @param array{function: string, columns: array<Expression|string>} $aggregate
     */
    protected function compileAggregate(Builder $query, array $aggregate): string
    {
        $column = $this->columnize($aggregate['columns']);

        if ($query->distinct && $column !== '*') {
            $column = 'DISTINCT(' . $column . ')';
        }

        $call = strtoupper($aggregate['function']) . '(' . $column . ')';

        return 'SELECT ' . ($column === '*' ? $call : $call . ' AS ' . $this->wrap('aggregate'));
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * @param array<Expression|string> $columns
     */
    protected function compileColumns(Builder $query, array $columns): ?string
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses, as it will need some
        // more syntax that is best handled by that function to keep things neat.
        if (! is_null($query->aggregate)) {
            return null;
        }

        // InfluxQL's DISTINCT is a function applied per column rather than a
        // keyword on the clause, so it is wrapped around each column here.
        $columns = array_map(
            $query->distinct ? $this->wrapDistinct(...) : $this->wrap(...),
            $columns,
        );

        return 'SELECT ' . implode(', ', $columns);
    }

    /**
     * Compile the "into" portion of the query.
     *
     * @throws RuntimeException on a version without `SELECT ... INTO`: InfluxDB 2.x and 3
     */
    protected function compileInto(Builder $query, Expression|string $into): string
    {
        $this->ensureVersionCompilesInto($this->version());

        return 'INTO ' . $this->wrapMeasurement($into);
    }

    /**
     * Compile the "from" portion of the query.
     */
    protected function compileFrom(Builder $query, Expression|Regex|string $from): string
    {
        return 'FROM ' . $this->wrapMeasurement($from);
    }

    /**
     * Compile the "where" portions of the query.
     */
    public function compileWheres(Builder $query): string
    {
        // Each type of where clause has its own compiler function, which is responsible
        // for actually creating the where clauses SQL. This helps keep the code nice
        // and maintainable since each clause has a very small method that it uses.
        if (! $query->wheres) {
            return '';
        }

        // If we actually have some where clauses, we will strip off the first boolean
        // operator, which is added by the query builders for convenience so we can
        // avoid checking for the first clauses in each of the compilers methods.
        return 'WHERE ' . $this->removeLeadingBoolean(implode(' ', $this->compileWheresToArray($query)));
    }

    /**
     * Get an array of all the where clauses for the query.
     *
     * @return list<string>
     */
    protected function compileWheresToArray(Builder $query): array
    {
        return array_map(
            fn (array $where): string => strtoupper($where['boolean']) . ' ' . $this->{"where{$where['type']}"}($query, $where),
            $query->wheres,
        );
    }

    /**
     * Compile a raw where clause.
     *
     * @param array{sql: Expression|string} $where
     */
    protected function whereRaw(Builder $query, array $where): string
    {
        return $where['sql'] instanceof Expression ? (string) $where['sql']->getValue($this) : $where['sql'];
    }

    /**
     * Compile a basic where clause.
     *
     * @param array{column: Expression|string, operator: string, value: mixed} $where
     */
    protected function whereBasic(Builder $query, array $where): string
    {
        return $this->wrap($where['column']) . ' ' . $where['operator'] . ' ' . $this->parameter($where['value']);
    }

    /**
     * Compile a where clause comparing two columns.
     *
     * @param array{first: Expression|string, operator: string, second: Expression|string} $where
     */
    protected function whereColumn(Builder $query, array $where): string
    {
        return $this->wrap($where['first']) . ' ' . $where['operator'] . ' ' . $this->wrap($where['second']);
    }

    /**
     * Compile a "where in" clause.
     *
     * InfluxQL has no IN, so the list becomes the OR of one equality per value.
     * An empty list matches nothing, as `0 = 1` does in Hypervel.
     *
     * @param array{column: Expression|string, values: array<mixed>} $where
     */
    protected function whereIn(Builder $query, array $where): string
    {
        if (empty($where['values'])) {
            return '0 = 1';
        }

        $column = $this->wrap($where['column']);

        return '(' . implode(' OR ', array_map(
            fn ($value): string => $column . ' = ' . $this->parameter($value),
            $where['values'],
        )) . ')';
    }

    /**
     * Compile a "where not in" clause.
     *
     * The AND of one inequality per value; an empty list matches everything.
     *
     * @param array{column: Expression|string, values: array<mixed>} $where
     */
    protected function whereNotIn(Builder $query, array $where): string
    {
        if (empty($where['values'])) {
            return '1 = 1';
        }

        $column = $this->wrap($where['column']);

        return '(' . implode(' AND ', array_map(
            fn ($value): string => $column . ' != ' . $this->parameter($value),
            $where['values'],
        )) . ')';
    }

    /**
     * Compile a "between" where clause.
     *
     * InfluxQL has no BETWEEN, so the bounds become two comparisons. Both are
     * inclusive, as BETWEEN is; the negation is exclusive on both sides.
     *
     * @param array{column: Expression|string, values: array<mixed>, not: bool} $where
     */
    protected function whereBetween(Builder $query, array $where): string
    {
        $column = $this->wrap($where['column']);

        $min = $this->parameter($where['values'][0]);

        $max = $this->parameter($where['values'][1]);

        return $where['not']
            ? '(' . $column . ' < ' . $min . ' OR ' . $column . ' > ' . $max . ')'
            : '(' . $column . ' >= ' . $min . ' AND ' . $column . ' <= ' . $max . ')';
    }

    /**
     * Compile a nested where clause.
     *
     * @param array{query: Builder} $where
     */
    protected function whereNested(Builder $query, array $where): string
    {
        // The nested query compiles with a leading "WHERE " of its own, which
        // is exactly six characters long and has no place inside parentheses.
        return '(' . substr($this->compileWheres($where['query']), 6) . ')';
    }

    /**
     * Compile the "group by" portions of the query.
     *
     * @param array<Expression|string> $groups
     */
    protected function compileGroups(Builder $query, array $groups): string
    {
        return 'GROUP BY ' . $this->columnize($groups);
    }

    /**
     * Compile the "fill()" option of a GROUP BY time() query.
     *
     * A string is one of InfluxQL's fill keywords, which the builder has
     * already checked; a number is the constant to fill with.
     */
    protected function compileFill(Builder $query, string|int|float $fill): string
    {
        return 'fill(' . (is_string($fill) ? $fill : $this->escape($fill)) . ')';
    }

    /**
     * Compile the "order by" portions of the query.
     *
     * @param list<array{column?: Expression|string, direction?: string, sql?: Expression|string}> $orders
     */
    protected function compileOrders(Builder $query, array $orders): string
    {
        if (empty($orders)) {
            return '';
        }

        return 'ORDER BY ' . implode(', ', array_map(function (array $order): string {
            if (isset($order['sql'])) {
                return $order['sql'] instanceof Expression ? (string) $order['sql']->getValue($this) : $order['sql'];
            }

            return $this->wrap($order['column']) . ' ' . strtoupper($order['direction']);
        }, $orders));
    }

    /**
     * Compile the "limit" portions of the query.
     */
    protected function compileLimit(Builder $query, int $limit): string
    {
        return 'LIMIT ' . $limit;
    }

    /**
     * Compile the "offset" portions of the query.
     */
    protected function compileOffset(Builder $query, int $offset): string
    {
        return 'OFFSET ' . $offset;
    }

    /**
     * Compile the "slimit" portions of the query: the number of series to return.
     *
     * @throws RuntimeException on InfluxDB 3, for any number of series but 0, which compiles to nothing
     */
    protected function compileSlimit(Builder $query, int $slimit): string
    {
        return $this->compileSeriesLimit($this->version(), 'SLIMIT', $slimit);
    }

    /**
     * Compile the "soffset" portions of the query: the number of series to skip.
     *
     * @throws RuntimeException on InfluxDB 3, for any number of series but 0, which compiles to nothing
     */
    protected function compileSoffset(Builder $query, int $soffset): string
    {
        return $this->compileSeriesLimit($this->version(), 'SOFFSET', $soffset);
    }

    /**
     * Compile the "tz()" clause that localises the returned timestamps.
     */
    protected function compileTimezone(Builder $query, string $timezone): string
    {
        return 'tz(' . $this->quoteString($timezone) . ')';
    }

    /**
     * Concatenate an array of segments, removing empties.
     *
     * @param array<string, null|string> $segments
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter($segments, fn (?string $value): bool => (string) $value !== ''));
    }

    /**
     * Remove the leading boolean from a statement.
     */
    protected function removeLeadingBoolean(string $value): string
    {
        return (string) preg_replace('/^(?:AND|OR) /', '', $value, 1);
    }

    /**
     * Wrap an array of values.
     *
     * @param array<Expression|string> $values
     * @return list<string>
     */
    public function wrapArray(array $values): array
    {
        return array_map($this->wrap(...), array_values($values));
    }

    /**
     * Wrap a measurement in keyword identifiers.
     *
     * A dotted name is InfluxQL's fully-qualified form and is wrapped segment
     * by segment: `rp.cpu` becomes `"rp"."cpu"`, and `db..cpu` keeps its empty
     * middle segment, which stands for the default retention policy. A
     * measurement whose own name contains a dot is passed as an Expression.
     *
     * A Regex compiles to a placeholder: the pattern is a binding, embedded
     * with the other values when the statement is sent.
     */
    public function wrapMeasurement(Expression|Regex|string $measurement): string
    {
        if ($measurement instanceof Expression) {
            return (string) $this->getValue($measurement);
        }

        if ($measurement instanceof Regex) {
            return '?';
        }

        return implode('.', array_map(
            fn (string $segment): string => $segment === '' ? '' : $this->wrapValue($segment),
            explode('.', $measurement),
        ));
    }

    /**
     * Wrap a column in keyword identifiers.
     *
     * Unlike a SQL grammar this never splits on dots: InfluxQL has no
     * `table.column` qualification, and a dot is an ordinary character in a
     * field or tag key. An alias (`value as v`) and a type hint (`host::tag`)
     * are the two shapes that are taken apart.
     */
    public function wrap(Expression|string $value): string
    {
        if ($value instanceof Expression) {
            return (string) $this->getValue($value);
        }

        // If the value being wrapped has a column alias we will need to separate out
        // the pieces so we can wrap each of the segments of the expression on its
        // own, and then join these both back together using the "AS" connector.
        if (stripos($value, ' as ') !== false) {
            return $this->wrapAliasedValue($value);
        }

        return $this->wrapValue($value);
    }

    /**
     * Wrap a column inside InfluxQL's DISTINCT() function, keeping any alias outside it.
     */
    protected function wrapDistinct(Expression|string $value): string
    {
        if (is_string($value) && stripos($value, ' as ') !== false) {
            $segments = preg_split('/\s+as\s+/i', $value, 2);

            return 'DISTINCT(' . $this->wrap($segments[0]) . ') AS ' . $this->wrapValue($segments[1]);
        }

        return 'DISTINCT(' . $this->wrap($value) . ')';
    }

    /**
     * Wrap a value that has an alias.
     */
    protected function wrapAliasedValue(string $value): string
    {
        $segments = preg_split('/\s+as\s+/i', $value, 2);

        return $this->wrap($segments[0]) . ' AS ' . $this->wrapValue($segments[1]);
    }

    /**
     * Wrap a single string in keyword identifiers, as Dialect::quoteIdentifier() quotes one.
     *
     * A trailing `::tag`, `::field` or type cast stays outside the quotes.
     */
    protected function wrapValue(string $value): string
    {
        return Dialect::quoteIdentifier($value);
    }

    /**
     * Convert an array of column names into a delimited string.
     *
     * @param array<Expression|string> $columns
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map($this->wrap(...), $columns));
    }

    /**
     * Create query parameter place-holders for an array.
     *
     * @param array<mixed> $values
     */
    public function parameterize(array $values): string
    {
        return implode(', ', array_map($this->parameter(...), $values));
    }

    /**
     * Get the appropriate query parameter place-holder for a value.
     */
    public function parameter(mixed $value): string
    {
        return $value instanceof Expression ? (string) $this->getValue($value) : '?';
    }

    /**
     * Quote the given string literal, as Dialect::quoteString() quotes one.
     *
     * @param list<string>|string $value
     */
    public function quoteString(string|array $value): string
    {
        return Dialect::quoteString($value);
    }

    /**
     * Quote a regular expression as the /pattern/ literal InfluxQL reads, as Dialect::quoteRegex() does.
     */
    public function quoteRegex(Regex $regex): string
    {
        return Dialect::quoteRegex($regex);
    }

    /**
     * Escape a value for safe embedding in a statement.
     *
     * An Expression is embedded as written; any other value becomes the
     * literal Dialect::escape() writes for it.
     *
     * @throws InvalidArgumentException for a value InfluxQL has no literal
     *                                  for: null, NAN, INF, an array or an
     *                                  arbitrary object
     */
    public function escape(mixed $value): string
    {
        return $value instanceof Expression ? (string) $this->getValue($value) : Dialect::escape($value);
    }

    /**
     * Format a date as the RFC3339 timestamp literal InfluxQL reads, in UTC, as Dialect::formatDateTime() does.
     */
    public function formatDateTime(DateTimeInterface $value): string
    {
        return Dialect::formatDateTime($value);
    }

    /**
     * Get the format for timestamps embedded in a statement.
     */
    public function getDateFormat(): string
    {
        return Dialect::DATE_FORMAT;
    }

    /**
     * Substitute the given bindings into the given statement.
     *
     * Each binding is escaped as escape() writes it, then embedded in place of
     * the `?` it fills: Dialect::substituteBindings() skips a placeholder
     * inside a string literal or a quoted identifier, and does not recognise
     * one inside a raw regular expression literal — pass a Regex through the
     * bindings instead.
     *
     * @param array<mixed> $bindings
     */
    public function substituteBindingsIntoRawSql(string $sql, array $bindings): string
    {
        return Dialect::substituteBindings($sql, array_map($this->escape(...), $bindings));
    }

    /**
     * Determine if the given value is a raw expression.
     */
    public function isExpression(mixed $value): bool
    {
        return $value instanceof Expression;
    }

    /**
     * Transforms expressions to their scalar types.
     */
    public function getValue(Expression|string|int|float $expression): string|int|float
    {
        if ($expression instanceof Expression) {
            return $this->getValue($expression->getValue($this));
        }

        return $expression;
    }

    /**
     * Get the grammar specific operators.
     *
     * @return list<string>
     */
    public function getOperators(): array
    {
        return $this->operators;
    }

    /**
     * Get the InfluxDB version the grammar compiles for, whose rules decide what it refuses.
     */
    abstract protected function version(): Version;
}
