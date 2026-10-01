<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Driver;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\Grammar as QueryGrammar;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Dialect;
use Ipsocode\InfluxDB\InfluxQL\Grammars\Concerns\RefusesWhatTheVersionLacks;
use Ipsocode\InfluxDB\InfluxQL\Version;
use LogicException;
use RuntimeException;

/**
 * Compiles Hypervel's query builder into InfluxQL, for the `influxql` database driver.
 *
 * The twin of Grammars\Grammar: the same Dialect writes identifiers and literals,
 * and the same rules refuse what the connection's InfluxDB version does not run.
 * What InfluxQL cannot express is refused as the statement compiles, not dropped
 * or approximated. Keywords are lowercase, as in Hypervel; InfluxQL reads any case.
 *
 * @see docs/influxql-driver.md#what-to-expect
 */
class Grammar extends QueryGrammar
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
     * Joins and havings stay so that their compilers refuse them; a lock and an
     * index hint are left out, so they compile to nothing.
     *
     * @var list<string>
     */
    protected array $selectComponents = [
        'aggregate',
        'columns',
        'into',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'fill',
        'orders',
        'limit',
        'offset',
        'slimit',
        'soffset',
        'timezone',
    ];

    /**
     * Create a new InfluxQL query grammar instance.
     *
     * @param Version $version the InfluxDB version the connection's server runs, which decides what is refused
     * @param null|string $retentionPolicy the retention policy the connection addresses, if any, which decides whether InfluxDB 2.x can run a DELETE
     */
    public function __construct(
        Connection $connection,
        protected Version $version,
        protected ?string $retentionPolicy = null,
    ) {
        parent::__construct($connection);
    }

    /**
     * Compile an aggregated select clause.
     *
     * AVG compiles to MEAN. A wildcard is not aliased `aggregate`: InfluxQL expands
     * `count(*)` into one column per field and refuses an alias on it.
     *
     * @param array{function: string, columns: array<Expression|string>} $aggregate
     */
    protected function compileAggregate(Builder $query, array $aggregate): string
    {
        $function = strtolower($aggregate['function']);

        $column = $this->columnize($aggregate['columns']);

        if (is_array($query->distinct)) {
            $column = 'distinct(' . $this->columnize($query->distinct) . ')';
        } elseif ($query->distinct && $column !== '*') {
            $column = 'distinct(' . $column . ')';
        }

        $call = ($function === 'avg' ? 'mean' : $function) . '(' . $column . ')';

        return 'select ' . ($column === '*' ? $call : $call . ' as ' . $this->wrap('aggregate'));
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * InfluxQL's DISTINCT is a function of one field, not a keyword on the
     * clause, so it is wrapped around each column.
     *
     * @param array<Expression|string> $columns
     *
     * @throws RuntimeException for distinct() given columns: InfluxQL has no DISTINCT ON
     */
    protected function compileColumns(Builder $query, array $columns): ?string
    {
        if (! is_null($query->aggregate)) {
            return null;
        }

        if (is_array($query->distinct)) {
            throw new RuntimeException('InfluxQL has no DISTINCT ON; call distinct() without columns, and it applies to each selected field.');
        }

        return 'select ' . implode(', ', array_map(
            $query->distinct ? $this->wrapDistinct(...) : $this->wrap(...),
            $columns,
        ));
    }

    /**
     * Compile the "into" portion of the query: the measurement a `SELECT ... INTO` writes to.
     *
     * @throws RuntimeException on a version without `SELECT ... INTO`: InfluxDB 2.x and 3
     */
    protected function compileInto(Builder $query, Expression|string $into): string
    {
        $this->ensureVersionCompilesInto($this->version);

        return 'into ' . $this->wrapTable($into);
    }

    /**
     * Refuse the "join" portions of the query: InfluxQL has no joins.
     *
     * @throws RuntimeException
     */
    protected function compileJoins(Builder $query, array $joins): string
    {
        throw new RuntimeException('InfluxQL has no joins; query each measurement on its own.');
    }

    /**
     * Get an array of all the where clauses for the query.
     *
     * @throws RuntimeException for whereNot() and orWhereNot(): InfluxQL has no NOT
     */
    protected function compileWheresToArray(Builder $query): array
    {
        foreach ($query->wheres as $where) {
            if (str_contains($where['boolean'], 'not')) {
                throw new RuntimeException('InfluxQL has no NOT; negate the comparison instead, such as != for = or !~ for =~.');
            }
        }

        return parent::compileWheresToArray($query);
    }

    /**
     * Refuse a "where binary" clause: InfluxQL has no binary comparison.
     *
     * @throws RuntimeException
     */
    protected function whereBinary(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no binary comparison.');
    }

    /**
     * Refuse a bitwise operator where clause: InfluxQL has none.
     *
     * @throws RuntimeException
     */
    protected function whereBitwise(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no bitwise where clause; compare the result of the operation in whereRaw() instead.');
    }

    /**
     * Refuse a "where like" clause: InfluxQL has no LIKE.
     *
     * @throws RuntimeException
     */
    protected function whereLike(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no LIKE; match a regular expression with =~ instead.');
    }

    /**
     * Compile a "where in" clause.
     *
     * InfluxQL has no IN, so the list becomes the OR of one equality per value.
     * An empty list matches nothing, as `0 = 1` does in Hypervel.
     */
    protected function whereIn(Builder $query, array $where): string
    {
        if (empty($where['values'])) {
            return '0 = 1';
        }

        $column = $this->wrap($where['column']);

        return '(' . implode(' or ', array_map(
            fn (mixed $value): string => $column . ' = ' . $this->parameter($value),
            $where['values'],
        )) . ')';
    }

    /**
     * Compile a "where not in" clause.
     *
     * The AND of one inequality per value; an empty list matches everything.
     */
    protected function whereNotIn(Builder $query, array $where): string
    {
        if (empty($where['values'])) {
            return '1 = 1';
        }

        $column = $this->wrap($where['column']);

        return '(' . implode(' and ', array_map(
            fn (mixed $value): string => $column . ' != ' . $this->parameter($value),
            $where['values'],
        )) . ')';
    }

    /**
     * Refuse a "where not in raw" clause: InfluxQL has no NOT IN.
     *
     * @throws RuntimeException
     */
    protected function whereNotInRaw(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no NOT IN; use whereNotIn(), which compiles to one comparison per value.');
    }

    /**
     * Refuse a "where in raw" clause: InfluxQL has no IN.
     *
     * @throws RuntimeException
     */
    protected function whereInRaw(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no IN; use whereIn(), which compiles to one comparison per value.');
    }

    /**
     * Refuse a "where null" clause: InfluxQL has no IS NULL.
     *
     * @throws RuntimeException
     */
    protected function whereNull(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no IS NULL; a field or tag cannot be compared with null.');
    }

    /**
     * Refuse a "where not null" clause: InfluxQL has no IS NOT NULL.
     *
     * @throws RuntimeException
     */
    protected function whereNotNull(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no IS NOT NULL; a field or tag cannot be compared with null.');
    }

    /**
     * Refuse a "where null safe equals" clause: InfluxQL has no null-safe comparison.
     *
     * @throws RuntimeException
     */
    protected function whereNullSafeEquals(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no null-safe comparison; a field or tag cannot be compared with null.');
    }

    /**
     * Compile a "between" where clause.
     *
     * InfluxQL has no BETWEEN, so the bounds become two comparisons. Both are
     * inclusive, as BETWEEN is; the negation is exclusive on both sides.
     */
    protected function whereBetween(Builder $query, array $where): string
    {
        $column = $this->wrap($where['column']);

        $min = $this->parameter(array_first($where['values']));

        $max = $this->parameter(array_last($where['values']));

        return $where['not']
            ? '(' . $column . ' < ' . $min . ' or ' . $column . ' > ' . $max . ')'
            : '(' . $column . ' >= ' . $min . ' and ' . $column . ' <= ' . $max . ')';
    }

    /**
     * Refuse a "between columns" where clause: InfluxQL has no BETWEEN.
     *
     * @throws RuntimeException
     */
    protected function whereBetweenColumns(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no BETWEEN; compare the column with each bound in its own whereColumn() instead.');
    }

    /**
     * Refuse a "value between" where clause: InfluxQL has no BETWEEN.
     *
     * @throws RuntimeException
     */
    protected function whereValueBetween(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no BETWEEN; compare each column with the value in its own where() instead.');
    }

    /**
     * Refuse a date based where clause, such as whereDate(): InfluxQL cannot compare part of a timestamp.
     *
     * @throws RuntimeException
     */
    protected function dateBasedWhere(string $type, Builder $query, array $where): string
    {
        throw new RuntimeException(sprintf('InfluxQL cannot compare the %s part of a timestamp; compare time with a date instead.', $type));
    }

    /**
     * Refuse a where condition with a sub-select: InfluxQL cannot compare a column with one.
     *
     * @throws RuntimeException
     */
    protected function whereSub(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL cannot compare a column with a sub-select.');
    }

    /**
     * Refuse a where exists clause: InfluxQL has no EXISTS.
     *
     * @throws RuntimeException
     */
    protected function whereExists(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no EXISTS; query the other measurement on its own instead.');
    }

    /**
     * Refuse a where not exists clause: InfluxQL has no NOT EXISTS.
     *
     * @throws RuntimeException
     */
    protected function whereNotExists(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no NOT EXISTS; query the other measurement on its own instead.');
    }

    /**
     * Refuse a where row values condition: InfluxQL has no row values.
     *
     * @throws RuntimeException
     */
    protected function whereRowValues(Builder $query, array $where): string
    {
        throw new RuntimeException('InfluxQL has no row values; compare each column in its own where() instead.');
    }

    /**
     * Refuse the "having" portions of the query: InfluxQL has no HAVING.
     *
     * @throws RuntimeException
     */
    protected function compileHavings(Builder $query): string
    {
        throw new RuntimeException('InfluxQL has no HAVING; filter the aggregate in an outer query that selects from this one instead.');
    }

    /**
     * Compile the "fill()" option of a GROUP BY time() query.
     *
     * A string is one of InfluxQL's fill keywords, which the builder has
     * already checked; a number is the constant to fill with.
     */
    protected function compileFill(Builder $query, string|int|float $fill): string
    {
        return 'fill(' . (is_string($fill) ? $fill : Dialect::escape($fill)) . ')';
    }

    /**
     * Refuse a random order, since InfluxQL sorts by time only.
     *
     * @throws RuntimeException
     */
    public function compileRandom(string|int $seed): string
    {
        throw new RuntimeException('InfluxQL sorts by time only; it cannot order randomly.');
    }

    /**
     * Refuse a group limit clause: it needs a window function, which InfluxQL lacks.
     *
     * @throws RuntimeException
     */
    protected function compileGroupLimit(Builder $query): string
    {
        throw new RuntimeException('InfluxQL has no window functions to limit each group; a limit on a query grouped by tags applies to each series instead.');
    }

    /**
     * Compile the "slimit" portions of the query: the number of series to return.
     *
     * @throws RuntimeException on InfluxDB 3, for any number of series but 0, which compiles to nothing
     */
    protected function compileSlimit(Builder $query, int $slimit): string
    {
        return $this->compileSeriesLimit($this->version, 'slimit', $slimit);
    }

    /**
     * Compile the "soffset" portions of the query: the number of series to skip.
     *
     * @throws RuntimeException on InfluxDB 3, for any number of series but 0, which compiles to nothing
     */
    protected function compileSoffset(Builder $query, int $soffset): string
    {
        return $this->compileSeriesLimit($this->version, 'soffset', $soffset);
    }

    /**
     * Compile the "tz()" clause that localises the returned timestamps.
     */
    protected function compileTimezone(Builder $query, string $timezone): string
    {
        return 'tz(' . $this->quoteString($timezone) . ')';
    }

    /**
     * Refuse the "union" queries attached to the main query: InfluxQL has no UNION.
     *
     * @throws RuntimeException
     */
    protected function compileUnions(Builder $query): string
    {
        throw new RuntimeException('InfluxQL has no UNION; run each query on its own.');
    }

    /**
     * Compile an exists statement into InfluxQL: the query itself, for one point.
     *
     * InfluxQL has no EXISTS, so the statement selects at most one point, and
     * the query has results when that point comes back.
     */
    public function compileExists(Builder $query): string
    {
        return $this->compileSelect($query->clone()->limit(1));
    }

    /**
     * Refuse an insert statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileInsert(Builder $query, array $values): string
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an insert ignore statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileInsertOrIgnore(Builder $query, array $values): string
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an insert or ignore statement with a returning clause: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileInsertOrIgnoreReturning(Builder $query, array $values, array $returning, ?array $uniqueBy): string
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an insert and get ID statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileInsertGetId(Builder $query, array $values, ?string $sequence): string
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an insert statement using a subquery: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileInsertUsing(Builder $query, array $columns, string $sql): string
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an insert ignore statement using a subquery: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql): string
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an update statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileUpdate(Builder $query, array $values): string
    {
        $this->refuseWrites();
    }

    /**
     * Refuse an "upsert" statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $this->refuseWrites();
    }

    /**
     * Compile a delete statement into InfluxQL: the measurement and the where clause.
     *
     * A version that cannot run the DELETE refuses it first, whatever the measurement.
     * A join, a limit, an offset, a slimit or a soffset is refused too: InfluxQL cannot
     * narrow a DELETE by one.
     *
     * @throws InvalidArgumentException when the query has no measurement, or a qualified or aliased one
     * @throws RuntimeException on InfluxDB 3, on 2.x when the connection addresses a retention policy, or when the query joins, limits, offsets, slimits or soffsets
     *
     * @see docs/influxql.md#deleting-points
     */
    public function compileDelete(Builder $query): string
    {
        $this->ensureVersionCompilesDelete($this->version, $this->retentionPolicy);

        $this->ensureDeleteTakesABareMeasurement($query->from);

        $this->ensureDeleteTakesNoLimitOrOffset(
            (bool) $query->joins || isset($query->limit) || isset($query->offset) || isset($query->slimit) || isset($query->soffset),
            withJoins: true,
        );

        return trim('delete from ' . $this->wrapTable($query->from) . ' ' . $this->compileWheres($query));
    }

    /**
     * Refuse a truncate table statement: InfluxQL has none.
     *
     * @throws LogicException
     */
    public function compileTruncate(Builder $query): array
    {
        $this->refuseWrites();
    }

    /**
     * Refuse a statement that would write points, which InfluxQL does not.
     *
     * @throws LogicException
     */
    protected function refuseWrites(): never
    {
        throw new LogicException('InfluxQL has no INSERT, UPDATE, UPSERT or TRUNCATE; write points through InfluxDB::writeApi() instead.');
    }

    /**
     * Substitute the given bindings into the given raw InfluxQL query.
     *
     * The connection sends the result, so values are written by Dialect::escape(): the
     * connection's escape() has no literal for a regular expression or a date. A `?` in
     * a raw regular expression is read as a placeholder too: pass a Regex as a binding.
     *
     * @param array<mixed> $bindings
     *
     * @throws InvalidArgumentException for a value InfluxQL has no literal for
     */
    public function substituteBindingsIntoRawSql(string $sql, array $bindings): string
    {
        return Dialect::substituteBindings($sql, array_map(
            fn (mixed $value): string => $value instanceof Expression ? (string) $this->getValue($value) : Dialect::escape($value),
            $bindings,
        ));
    }

    /**
     * Wrap a table in keyword identifiers: a measurement, qualified segment by segment.
     *
     * `db..cpu` keeps its empty middle segment (the default retention policy); a name
     * with a dot of its own is an Expression. The connection's table prefix is not applied.
     *
     * @throws InvalidArgumentException for an aliased name, such as `cpu as c`
     */
    public function wrapTable(Expression|string $table, ?string $prefix = null): string
    {
        if ($table instanceof Expression) {
            return (string) $this->getValue($table);
        }

        if (stripos($table, ' as ') !== false) {
            throw new InvalidArgumentException(sprintf('InfluxQL cannot alias a measurement, as [%s] does; name the measurement alone.', $table));
        }

        return implode('.', array_map(
            fn (string $segment): string => $segment === '' ? '' : $this->wrapValue($segment),
            explode('.', $table),
        ));
    }

    /**
     * Wrap a value in keyword identifiers.
     *
     * A column is never split on dots: InfluxQL has no `measurement.column`
     * qualification, and a dot is an ordinary character in a field or tag key.
     */
    public function wrap(Expression|string $value): string|int|float
    {
        if ($value instanceof Expression) {
            return $this->getValue($value);
        }

        if (stripos($value, ' as ') !== false) {
            return $this->wrapAliasedValue($value);
        }

        return $this->wrapValue($value);
    }

    /**
     * Wrap a column in InfluxQL's distinct() function, keeping any alias outside it.
     */
    protected function wrapDistinct(Expression|string $value): string
    {
        if (is_string($value) && stripos($value, ' as ') !== false) {
            $segments = preg_split('/\s+as\s+/i', $value, 2);

            return 'distinct(' . $this->wrap($segments[0]) . ') as ' . $this->wrapValue($segments[1]);
        }

        return 'distinct(' . $this->wrap($value) . ')';
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
     * Quote the given string literal, escaping a quote with a backslash, as Dialect::quoteString() does.
     *
     * @param array<string>|string $value
     */
    public function quoteString(string|array $value): string
    {
        return Dialect::quoteString($value);
    }

    /**
     * Get the format for timestamps embedded in a statement: RFC3339, in UTC.
     */
    public function getDateFormat(): string
    {
        return Dialect::DATE_FORMAT;
    }
}
