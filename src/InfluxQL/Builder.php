<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use BackedEnum;
use BadMethodCallException;
use Closure;
use DatePeriod;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Grammars\Grammar;
use RuntimeException;
use SortDirection;
use stdClass;
use UnitEnum;

/**
 * A fluent InfluxQL query builder for InfluxDB 1.x, 2.x and 3.
 *
 * The statements compile for the InfluxDB version the connection names, its
 * `version`, and the grammar refuses what that version does not run, as the
 * statement compiles and before anything is sent. InfluxDB 1.x runs the whole
 * language. For 2.x, the grammar refuses `into()`, and `delete()` on a
 * connection that addresses a retention policy. For 3, it refuses `into()`,
 * `delete()`, and `slimit()` or `soffset()` with any value but 0. Before it
 * sends its first statement on a connection, the builder also reads the
 * server's version (Connection::getServerVersion()) and refuses a server that
 * runs another major version than the connection names, since its statements
 * were compiled for that one. Statements run directly on the connection are
 * not checked.
 *
 * Aligned with Hypervel\Database\Query\Builder (0.4): the methods, their
 * signatures, the public properties the grammar reads, the `?` placeholders
 * of toSql(), getBindings() and toRawSql(), and the way get(), first(),
 * value(), pluck(), exists(), the aggregates and chunk() run the query. Code
 * written against the SQL builder reads the same way here:
 *
 *     InfluxDB::table('cpu')
 *         ->select('usage_user', 'host')
 *         ->where('host', 'web1')
 *         ->where('time', '>=', now()->subHour())
 *         ->orderByDesc('time')
 *         ->limit(10)
 *         ->get();
 *
 * What InfluxQL cannot express is left out rather than approximated: there
 * are no joins, havings, unions, inserts or updates (points are written
 * through the WriteApi), no `whereNull`, `whereNot` or `whereLike`, and a
 * column can only be ordered by `time`. What it adds is InfluxQL-only:
 * groupByTime() with fill(), slimit() and soffset(), tz(), into(), regular
 * expressions through `=~`/`!~` or a Regex value, and delete().
 *
 * `whereIn` and `whereBetween` are kept, expanded into the comparisons
 * InfluxQL understands; a null value is refused, since InfluxQL has no null.
 *
 * @method static void macro(string $name, callable|object $macro)
 */
class Builder
{
    use Conditionable;
    use Tappable;
    use Macroable {
        __call as macroCall;
    }

    /**
     * The where clauses of Hypervel's builder that this one does not implement.
     *
     * Named so that a call to one fails loudly. Left to the dynamic where,
     * `whereNull('host')` would silently compile to `"null" = 'host'`, and
     * `wherePast('time')` to `"past" = 'time'`. The relative-date clauses of
     * BuildsWhereDateClauses are here too: InfluxQL could express them, but
     * they are written as a comparison on `time` instead.
     *
     * @var list<string>
     */
    private const array UNSUPPORTED_WHERES = [
        'Not', 'Null', 'NotNull', 'Like', 'NotLike', 'Date', 'Time', 'Day', 'Month', 'Year',
        'Exists', 'NotExists', 'Sub', 'All', 'Any', 'None', 'FullText', 'RowValues',
        'BetweenColumns', 'NotBetweenColumns', 'ValueBetween', 'ValueNotBetween',
        'IntegerInRaw', 'IntegerNotInRaw', 'Binary', 'NotBinary', 'NullSafeEquals',
        'JsonContains', 'JsonDoesntContain', 'JsonOverlaps', 'JsonDoesntOverlap',
        'JsonContainsKey', 'JsonDoesntContainKey', 'JsonLength',
        'VectorSimilarTo', 'VectorDistanceLessThan',
        'Past', 'Future', 'NowOrPast', 'NowOrFuture',
        'Today', 'BeforeToday', 'AfterToday', 'TodayOrBefore', 'TodayOrAfter',
    ];

    /**
     * The InfluxQL connection instance.
     */
    public Connection $connection;

    /**
     * The InfluxQL query grammar instance.
     */
    public Grammar $grammar;

    /**
     * The current query value bindings.
     *
     * @var array<string, list<mixed>>
     */
    public array $bindings = [
        'select' => [],
        'from' => [],
        'where' => [],
        'groupBy' => [],
        'order' => [],
    ];

    /**
     * An aggregate function and column to be run.
     *
     * @var null|array{function: string, columns: array<Expression|string>}
     */
    public ?array $aggregate = null;

    /**
     * The columns that should be returned.
     *
     * @var null|array<Expression|string>
     */
    public ?array $columns = null;

    /**
     * Indicates if the query returns distinct results.
     */
    public bool $distinct = false;

    /**
     * The measurement which the query is targeting.
     */
    public Expression|Regex|string|null $from = null;

    /**
     * The measurement a `SELECT ... INTO` writes to.
     */
    public Expression|string|null $into = null;

    /**
     * The where constraints for the query.
     *
     * @var list<array<string, mixed>>
     */
    public array $wheres = [];

    /**
     * The groupings for the query.
     *
     * @var null|array<Expression|string>
     */
    public ?array $groups = null;

    /**
     * The fill() option for a GROUP BY time() query.
     */
    public string|int|float|null $fill = null;

    /**
     * The orderings for the query.
     *
     * @var null|list<array<string, mixed>>
     */
    public ?array $orders = null;

    /**
     * The maximum number of points to return.
     */
    public ?int $limit = null;

    /**
     * The number of points to skip.
     */
    public ?int $offset = null;

    /**
     * The maximum number of series to return.
     */
    public ?int $slimit = null;

    /**
     * The number of series to skip.
     */
    public ?int $soffset = null;

    /**
     * The time zone the returned timestamps are localised to.
     */
    public ?string $timezone = null;

    /**
     * All of the available clause operators.
     *
     * @var list<string>
     */
    public array $operators = Dialect::OPERATORS;

    /**
     * Create a new query builder instance.
     */
    public function __construct(Connection $connection, ?Grammar $grammar = null)
    {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
    }

    /**
     * Set the columns to be selected.
     *
     * @param array<Expression|string>|Expression|string $columns
     */
    public function select(mixed $columns = ['*']): static
    {
        $this->columns = [];
        $this->bindings['select'] = [];

        foreach (is_array($columns) ? $columns : func_get_args() as $column) {
            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * Add a new "raw" select expression to the query.
     *
     * The way to select an InfluxQL function, since a function call is not
     * an identifier: `selectRaw('MEAN("value") AS "mean"')`.
     *
     * @param list<mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->addSelect(new Expression($expression));

        if ($bindings) {
            $this->addBinding($bindings, 'select');
        }

        return $this;
    }

    /**
     * Add a new select column to the query.
     *
     * @param array<Expression|string>|Expression|string $column
     */
    public function addSelect(mixed $column): static
    {
        foreach (is_array($column) ? $column : func_get_args() as $column) {
            if (is_array($this->columns) && in_array($column, $this->columns, true)) {
                continue;
            }

            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * Force the query to only return distinct results.
     *
     * Compiles to InfluxQL's DISTINCT() function around each selected column.
     */
    public function distinct(): static
    {
        $this->distinct = true;

        return $this;
    }

    /**
     * Set the measurement which the query is targeting.
     *
     * A dotted name is InfluxQL's qualified form, `db.rp.measurement` or
     * `rp.measurement`; a Regex matches several measurements; a Closure or a
     * Builder becomes a subquery. There is no alias: InfluxQL has none.
     */
    public function from(Closure|self|Expression|Regex|string $measurement): static
    {
        if ($measurement instanceof Closure || $measurement instanceof self) {
            return $this->fromSub($measurement);
        }

        $this->from = $measurement;

        $this->bindings['from'] = $measurement instanceof Regex ? [$measurement] : [];

        return $this;
    }

    /**
     * Add a raw from clause to the query.
     */
    public function fromRaw(Expression|string $expression, mixed $bindings = []): static
    {
        $this->from = $expression instanceof Expression ? $expression : new Expression($expression);

        $this->bindings['from'] = [];

        $this->addBinding($bindings, 'from');

        return $this;
    }

    /**
     * Makes "from" fetch from a subquery.
     */
    public function fromSub(Closure|self $query): static
    {
        [$query, $bindings] = $this->createSub($query);

        return $this->fromRaw('(' . $query . ')', $bindings);
    }

    /**
     * Creates a subquery and parse it.
     *
     * @return array{string, list<mixed>}
     */
    protected function createSub(Closure|self $query): array
    {
        // If the given query is a Closure, we will execute it while passing in a new
        // query instance to the Closure. This will give the developer a chance to
        // format and work with the query before we cast it to a raw SQL string.
        if ($query instanceof Closure) {
            $callback = $query;

            $callback($query = $this->newQuery());
        }

        return [$query->toSql(), $query->getBindings()];
    }

    /**
     * Set the measurement a `SELECT ... INTO` writes its result to.
     *
     * InfluxDB 1.x only: a statement with one fails to compile on a 2.x or 3
     * connection, neither of which has `SELECT ... INTO`.
     */
    public function into(Expression|string $measurement): static
    {
        $this->into = $measurement;

        return $this;
    }

    /**
     * Add a basic where clause to the query.
     *
     * A string value with the `=~` or `!~` operator is taken as a regular
     * expression; a Regex value turns `=` and `!=` into their regex forms.
     *
     * @param array<mixed>|Closure|Expression|string $column
     *
     * @throws InvalidArgumentException for a null value, a sub-select, an
     *                                  operator InfluxQL does not have, or one
     *                                  that cannot take a regular expression
     */
    public function where(Closure|Expression|array|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If the column is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parentheses.
        // We will add that Closure to the query and return back out immediately.
        if ($column instanceof Closure) {
            if (! is_null($operator)) {
                throw new InvalidArgumentException('InfluxQL cannot compare a sub-select; a Closure column starts a nested where and takes no operator or value.');
            }

            return $this->whereNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            $this->refuseUnsupportedOperator($operator, $value);

            [$value, $operator] = [$operator, '='];
        }

        if ($value instanceof Closure || $value instanceof self) {
            throw new InvalidArgumentException('InfluxQL cannot compare a column with a sub-select.');
        }

        // Hypervel turns a null value into a "where null" clause. InfluxQL has no
        // null literal and no null comparison, so there is nothing to turn it into.
        if (is_null($value)) {
            throw new InvalidArgumentException('InfluxQL has no null literal; a field or tag cannot be compared with null.');
        }

        [$value, $operator] = $this->prepareRegex($value, $operator);

        // Now that we are working with just a simple query we can put the elements
        // in our array and add the query binding to our array of bindings that
        // will be embedded in the statement when it is finally executed.
        $this->wheres[] = [
            'type' => 'Basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean,
        ];

        if (! $value instanceof Expression) {
            $this->addBinding($this->flattenValue($value), 'where');
        }

        return $this;
    }

    /**
     * Add an array of where clauses to the query.
     *
     * @param array<mixed> $column
     */
    protected function addArrayOfWheres(array $column, string $boolean, string $method = 'where'): static
    {
        return $this->whereNested(function (self $query) use ($column, $method, $boolean): void {
            foreach ($column as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $query->{$method}(...array_values($value), boolean: $boolean);
                } else {
                    $query->{$method}($key, '=', $value, $boolean);
                }
            }
        }, $boolean);
    }

    /**
     * Prepare the value and operator for a where clause.
     *
     * @return array{mixed, mixed}
     *
     * @throws InvalidArgumentException
     */
    public function prepareValueAndOperator(mixed $value, mixed $operator, bool $useDefault = false): array
    {
        if ($useDefault) {
            return [$operator, '='];
        }

        if ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * Prevents using Null values with invalid operators.
     */
    protected function invalidOperatorAndValue(mixed $operator, mixed $value): bool
    {
        return is_null($value) && in_array($operator, $this->operators, true)
            && ! in_array($operator, ['=', '<>', '!='], true);
    }

    /**
     * Determine if the given operator is supported.
     */
    protected function invalidOperator(mixed $operator): bool
    {
        return ! is_string($operator) || (! in_array(strtolower($operator), $this->operators, true)
            && ! in_array(strtolower($operator), $this->grammar->getOperators(), true));
    }

    /**
     * Refuse an operator InfluxQL does not have when a value follows it.
     *
     * Without a value, an invalid operator is the value itself, as in
     * `where('host', 'web1', null, 'or')`. With one, it was meant as an
     * operator — an SQL one such as `like` — and taking it for the value would
     * compile `where('host', 'like', 'web%')` to `"host" = 'like'`.
     *
     * @throws InvalidArgumentException
     */
    protected function refuseUnsupportedOperator(mixed $operator, mixed $value): void
    {
        if (! is_null($value)) {
            throw new InvalidArgumentException(sprintf(
                '[%s] is not an InfluxQL operator; use one of %s.',
                is_string($operator) ? $operator : get_debug_type($operator),
                implode(', ', $this->operators),
            ));
        }
    }

    /**
     * Pair a regular expression with the operator that matches it.
     *
     * @return array{mixed, string}
     *
     * @throws InvalidArgumentException
     */
    protected function prepareRegex(mixed $value, string $operator): array
    {
        if (in_array($operator, ['=~', '!~'], true)) {
            if (is_string($value)) {
                $value = new Regex($value);
            }

            if (! $value instanceof Regex) {
                throw new InvalidArgumentException(sprintf('The %s operator takes a regular expression, as a string or a Regex.', $operator));
            }

            return [$value, $operator];
        }

        if ($value instanceof Regex) {
            return [$value, match ($operator) {
                '=' => '=~',
                '!=', '<>' => '!~',
                default => throw new InvalidArgumentException(sprintf('A regular expression is matched with =~ or !~, not %s.', $operator)),
            }];
        }

        return [$value, $operator];
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param array<mixed>|Closure|Expression|string $column
     */
    public function orWhere(Closure|Expression|array|string $column, mixed $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a "where" clause comparing two columns to the query.
     *
     * @param array<mixed>|Expression|string $first
     */
    public function whereColumn(Expression|string|array $first, Expression|string|null $operator = null, Expression|string|null $second = null, string $boolean = 'and'): static
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($first)) {
            return $this->addArrayOfWheres($first, $boolean, 'whereColumn');
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ($this->invalidOperator($operator)) {
            $this->refuseUnsupportedOperator($operator, $second);

            [$second, $operator] = [$operator, '='];
        }

        // Finally, we will add this where clause into this array of clauses that we
        // are building for the query. All of them will be compiled via a grammar
        // once the query is about to be executed and run against the database.
        $this->wheres[] = [
            'type' => 'Column', 'first' => $first, 'operator' => $operator, 'second' => $second, 'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add an "or where" clause comparing two columns to the query.
     *
     * @param array<mixed>|Expression|string $first
     */
    public function orWhereColumn(Expression|string|array $first, Expression|string|null $operator = null, Expression|string|null $second = null): static
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    /**
     * Add a raw where clause to the query.
     *
     * A single binding may be passed bare. It is wrapped rather than cast, as
     * a cast would turn a Regex or a date into the array of its properties.
     */
    public function whereRaw(Expression|string $sql, mixed $bindings = [], string $boolean = 'and'): static
    {
        $this->wheres[] = ['type' => 'Raw', 'sql' => $sql, 'boolean' => $boolean];

        $this->addBinding(Arr::wrap($bindings), 'where');

        return $this;
    }

    /**
     * Add a raw "or where" clause to the query.
     */
    public function orWhereRaw(Expression|string $sql, mixed $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /**
     * Add a "where in" clause to the query.
     *
     * InfluxQL has no IN: the clause compiles to one equality per value, ORed.
     *
     * @param Arrayable<array<mixed>>|iterable<mixed> $values
     *
     * @throws InvalidArgumentException
     */
    public function whereIn(Expression|string $column, Arrayable|iterable $values, string $boolean = 'and', bool $not = false): static
    {
        $type = $not ? 'NotIn' : 'In';

        // Next, if the value is Arrayable we need to cast it to its raw array form so we
        // have the underlying array value instead of an Arrayable object which is not
        // able to be added as a binding, etc. We will then add to the wheres array.
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        $values = array_values(is_array($values) ? $values : iterator_to_array($values, false));

        if (count($values) !== count(Arr::flatten($values, 1))) {
            throw new InvalidArgumentException('Nested arrays may not be passed to whereIn method.');
        }

        $this->wheres[] = ['type' => $type, 'column' => $column, 'values' => $values, 'boolean' => $boolean];

        // Finally, we'll add a binding for each value unless that value is an expression
        // in which case we will just skip over it since it will be the query as a raw
        // string and not as a parameterized place-holder to be replaced at run time.
        $this->addBinding($this->cleanBindings($values), 'where');

        return $this;
    }

    /**
     * Add an "or where in" clause to the query.
     *
     * @param Arrayable<array<mixed>>|iterable<mixed> $values
     */
    public function orWhereIn(Expression|string $column, Arrayable|iterable $values): static
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * Add a "where not in" clause to the query.
     *
     * @param Arrayable<array<mixed>>|iterable<mixed> $values
     */
    public function whereNotIn(Expression|string $column, Arrayable|iterable $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not in" clause to the query.
     *
     * @param Arrayable<array<mixed>>|iterable<mixed> $values
     */
    public function orWhereNotIn(Expression|string $column, Arrayable|iterable $values): static
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    /**
     * Add a where between statement to the query.
     *
     * InfluxQL has no BETWEEN: the clause compiles to two inclusive comparisons.
     * The usual time window reads `whereBetween('time', [$from, $to])`, or
     * takes a DatePeriod such as a CarbonPeriod, whose start and end are the
     * bounds, as in Hypervel.
     *
     * @param iterable<mixed> $values
     *
     * @throws InvalidArgumentException when fewer than two bounds are given
     */
    public function whereBetween(Expression|string $column, iterable $values, string $boolean = 'and', bool $not = false): static
    {
        if ($values instanceof DatePeriod) {
            $values = $this->resolveDatePeriodBounds($values);
        }

        $values = array_values(is_array($values) ? $values : iterator_to_array($values, false));

        if (count($values) < 2) {
            throw new InvalidArgumentException('whereBetween needs a lower and an upper bound.');
        }

        $values = array_slice($values, 0, 2);

        $this->wheres[] = ['type' => 'Between', 'column' => $column, 'values' => $values, 'boolean' => $boolean, 'not' => $not];

        $this->addBinding($this->cleanBindings($values), 'where');

        return $this;
    }

    /**
     * Resolve the start and end dates from a DatePeriod.
     *
     * @return array{DateTimeInterface, DateTimeInterface}
     */
    protected function resolveDatePeriodBounds(DatePeriod $period): array
    {
        [$start, $end] = [$period->getStartDate(), $period->getEndDate()];

        if ($end === null) {
            $end = clone $start;
            $recurrences = $period->getRecurrences();

            for ($i = 0; $i < $recurrences; ++$i) {
                $end = $end->add($period->getDateInterval());
            }
        }

        return [$start, $end];
    }

    /**
     * Add an "or where between" statement to the query.
     *
     * @param iterable<mixed> $values
     */
    public function orWhereBetween(Expression|string $column, iterable $values): static
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * Add a where not between statement to the query.
     *
     * @param iterable<mixed> $values
     */
    public function whereNotBetween(Expression|string $column, iterable $values, string $boolean = 'and'): static
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * Add an "or where not between" statement to the query.
     *
     * @param iterable<mixed> $values
     */
    public function orWhereNotBetween(Expression|string $column, iterable $values): static
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param Closure(static): void $callback
     */
    public function whereNested(Closure $callback, string $boolean = 'and'): static
    {
        $callback($query = $this->forNestedWhere());

        return $this->addNestedWhereQuery($query, $boolean);
    }

    /**
     * Create a new query instance for nested where condition.
     */
    public function forNestedWhere(): static
    {
        $query = $this->newQuery();

        if (! is_null($this->from)) {
            $query->from($this->from);
        }

        return $query;
    }

    /**
     * Add another query builder as a nested where to the query builder.
     */
    public function addNestedWhereQuery(self $query, string $boolean = 'and'): static
    {
        if (count($query->wheres)) {
            $this->wheres[] = ['type' => 'Nested', 'query' => $query, 'boolean' => $boolean];

            $this->addBinding($query->getRawBindings()['where'], 'where');
        }

        return $this;
    }

    /**
     * Add a "group by" clause to the query.
     *
     * Tags only, or `*` for every tag; a time interval goes through groupByTime().
     *
     * @param array<Expression|string>|Expression|string ...$groups
     */
    public function groupBy(array|Expression|string ...$groups): static
    {
        foreach ($groups as $group) {
            $this->groups = array_merge(
                (array) $this->groups,
                Arr::wrap($group)
            );
        }

        return $this;
    }

    /**
     * Add a raw "groupBy" clause to the query.
     *
     * @param list<mixed> $bindings
     */
    public function groupByRaw(string $sql, array $bindings = []): static
    {
        $this->groups[] = new Expression($sql);

        $this->addBinding($bindings, 'groupBy');

        return $this;
    }

    /**
     * Group the points into windows of the given duration: `GROUP BY time(10m)`.
     *
     * Both arguments are InfluxQL duration literals (`10m`, `1h`, `-30m` for
     * the offset) and are checked as such, since they are embedded as written.
     *
     * @throws InvalidArgumentException
     */
    public function groupByTime(string $interval, ?string $offset = null): static
    {
        foreach (array_filter([$interval, $offset], fn (?string $duration): bool => $duration !== null) as $duration) {
            if (preg_match(Dialect::DURATION, $duration) !== 1) {
                throw new InvalidArgumentException(sprintf('[%s] is not an InfluxQL duration literal, such as 10m or 1h.', $duration));
            }
        }

        return $this->groupByRaw('time(' . $interval . ($offset === null ? '' : ', ' . $offset) . ')');
    }

    /**
     * Set what a GROUP BY time() window with no points reports: `fill(0)`, `fill(previous)`.
     *
     * @param float|int|string $value a number, or one of `none`, `null`, `previous`, `linear`
     *
     * @throws InvalidArgumentException
     */
    public function fill(string|int|float $value): static
    {
        if (is_string($value)) {
            if (is_numeric($value)) {
                $value += 0;
            } elseif (! in_array(strtolower($value), Dialect::FILLS, true)) {
                throw new InvalidArgumentException(sprintf('fill() takes a number or one of %s, not [%s].', implode(', ', Dialect::FILLS), $value));
            } else {
                $value = strtolower($value);
            }
        }

        $this->fill = $value;

        return $this;
    }

    /**
     * Add an "order by" clause to the query.
     *
     * InfluxQL sorts by time only, so the column is `time` unless a raw
     * expression is given.
     *
     * @throws InvalidArgumentException
     */
    public function orderBy(Expression|string $column = 'time', SortDirection|string $direction = 'asc'): static
    {
        if (! $column instanceof Expression && strtolower($column) !== 'time') {
            throw new InvalidArgumentException('InfluxQL sorts by time only; orderBy() takes "time" or a raw expression.');
        }

        $direction = match (true) {
            $direction instanceof SortDirection => $direction === SortDirection::Ascending ? 'asc' : 'desc',
            strtolower($direction) === 'asc' => 'asc',
            strtolower($direction) === 'desc' => 'desc',
            default => throw new InvalidArgumentException('Order direction must be a SortDirection, "asc" or "desc".'),
        };

        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    /**
     * Add a descending "order by" clause to the query.
     */
    public function orderByDesc(Expression|string $column = 'time'): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for the newest points first.
     */
    public function latest(Expression|string $column = 'time'): static
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * Add an "order by" clause for the oldest points first.
     */
    public function oldest(Expression|string $column = 'time'): static
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * Add a raw "order by" clause to the query.
     */
    public function orderByRaw(string $sql, mixed $bindings = []): static
    {
        $this->orders[] = ['type' => 'Raw', 'sql' => $sql];

        $this->addBinding($bindings, 'order');

        return $this;
    }

    /**
     * Remove all existing orders and optionally add a new order.
     */
    public function reorder(Expression|string|null $column = null, SortDirection|string $direction = 'asc'): static
    {
        $this->orders = null;
        $this->bindings['order'] = [];

        if ($column) {
            return $this->orderBy($column, $direction);
        }

        return $this;
    }

    /**
     * Alias to set the "offset" value of the query.
     */
    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    /**
     * Set the "offset" value of the query.
     */
    public function offset(?int $value): static
    {
        $this->offset = max(0, (int) $value);

        return $this;
    }

    /**
     * Alias to set the "limit" value of the query.
     */
    public function take(int $value): static
    {
        return $this->limit($value);
    }

    /**
     * Set the "limit" value of the query.
     */
    public function limit(?int $value): static
    {
        if (is_null($value) || $value >= 0) {
            $this->limit = $value;
        }

        return $this;
    }

    /**
     * Set the limit and offset for a given page.
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Set the maximum number of series to return: InfluxQL's SLIMIT.
     *
     * InfluxDB 3 has no SLIMIT: on a 3 connection a statement with any value
     * but 0, which sets no limit, fails to compile.
     */
    public function slimit(?int $value): static
    {
        if (is_null($value) || $value >= 0) {
            $this->slimit = $value;
        }

        return $this;
    }

    /**
     * Set the number of series to skip: InfluxQL's SOFFSET.
     *
     * InfluxDB 3 has no SOFFSET: on a 3 connection a statement with any value
     * but 0, which skips none, fails to compile.
     */
    public function soffset(?int $value): static
    {
        $this->soffset = max(0, (int) $value);

        return $this;
    }

    /**
     * Localise the returned timestamps to a time zone: InfluxQL's `tz()` clause.
     *
     * @throws InvalidArgumentException for a name PHP does not know either
     */
    public function tz(string $timezone): static
    {
        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            throw new InvalidArgumentException(sprintf('[%s] is not a time zone name.', $timezone));
        }

        $this->timezone = $timezone;

        return $this;
    }

    /**
     * Get the InfluxQL representation of the query, with `?` in place of each binding.
     */
    public function toSql(): string
    {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Get the InfluxQL representation of the query with embedded bindings — what is sent.
     */
    public function toRawSql(): string
    {
        return $this->embedBindings($this->toSql(), $this->getBindings());
    }

    /**
     * Execute the query as a "select" statement.
     *
     * Each row is an object keyed by column, `time` first, with the GROUP BY
     * tags of its series appended.
     *
     * @param array<Expression|string>|Expression|string $columns
     * @return Collection<int, stdClass>
     */
    public function get(Expression|array|string $columns = ['*']): Collection
    {
        return new Collection($this->onceWithColumns(Arr::wrap($columns), fn (): array => $this->runSelect()));
    }

    /**
     * Execute the query and return its series rather than flattened rows.
     *
     * @return list<Series>
     */
    public function series(): array
    {
        $results = $this->send($this->toSql(), $this->getBindings(), $this->connection->results(...));

        return $results === [] ? [] : $results[0]->series;
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return list<stdClass>
     */
    protected function runSelect(): array
    {
        return $this->send($this->toSql(), $this->getBindings(), $this->connection->select(...));
    }

    /**
     * Send a statement through the connection once its server is found to run the connection's version.
     *
     * @template TResult
     *
     * @param list<mixed> $bindings
     * @param Closure(string, list<mixed>): TResult $send
     * @return TResult
     *
     * @throws QueryException
     */
    protected function send(string $query, array $bindings, Closure $send): mixed
    {
        $this->ensureServerRunsTheConnectionsVersion($query, $bindings);

        return $send($query, $bindings);
    }

    /**
     * Refuse the statement unless the server runs the major version the connection names.
     *
     * The manager's connections know the version their server named when the
     * Hypervel server started (InfluxDBManager::detectServerVersions()). Any
     * other connection reads it once and keeps it, so this costs at most one
     * /ping per connection and worker. A refusal, like a ping that fails, is a
     * QueryException carrying the statement that was not sent.
     *
     * @param list<mixed> $bindings
     *
     * @throws QueryException
     */
    protected function ensureServerRunsTheConnectionsVersion(string $query, array $bindings): void
    {
        if (! $this->connection->knowsServerVersion()) {
            // The connection embeds the values before it sends a statement, so
            // one InfluxQL cannot express fails before anything is sent. A /ping
            // still to come would go out first, so embed them now as well.
            $this->embedBindings($query, $bindings);
        }

        try {
            $version = $this->connection->getServerVersion();
        } catch (Exception $exception) {
            throw new QueryException($this->connection->getName(), $this->embedBindings($query, $bindings), $bindings, $exception);
        }

        $expected = $this->connection->getVersion();

        if ($version === null || ! $expected->matches($version)) {
            throw new QueryException($this->connection->getName(), $this->embedBindings($query, $bindings), $bindings, sprintf(
                'The InfluxQL builder compiles for %s on this connection (version %s), but the server reports %s.',
                $expected->label(),
                $expected->value,
                $version === null ? 'no version' : "version [{$version}]",
            ));
        }
    }

    /**
     * Embed the bindings in a statement, as the connection embeds them before sending it.
     *
     * @param list<mixed> $bindings
     */
    protected function embedBindings(string $query, array $bindings): string
    {
        return $this->grammar->substituteBindingsIntoRawSql($query, $this->connection->prepareBindings($bindings));
    }

    /**
     * Execute the query and get the first result.
     *
     * @param array<Expression|string>|Expression|string $columns
     */
    public function first(Expression|array|string $columns = ['*']): ?stdClass
    {
        return $this->limit(1)->get($columns)->first();
    }

    /**
     * Get a single column's value from the first result of a query.
     */
    public function value(Expression|string $column): mixed
    {
        $result = (array) $this->first([$column]);

        if ($result === []) {
            return null;
        }

        if (is_string($column) && array_key_exists($column, $result)) {
            return $result[$column];
        }

        // Every row leads with the timestamp; the column asked for — under
        // whatever name the server gave it — is the first one after it.
        unset($result['time']);

        return $result === [] ? null : $result[array_key_first($result)];
    }

    /**
     * Get a collection instance containing the values of a given column.
     *
     * A tag can be plucked alongside a field, but not on its own: InfluxQL
     * returns no rows for a query that selects tags only.
     *
     * @return Collection<array-key, mixed>
     */
    public function pluck(Expression|string $column, ?string $key = null): Collection
    {
        // First, we will need to select the results of the query accounting for the
        // given columns / key. Once we have the results, we will be able to take
        // the results and get the exact data that was requested for the query.
        $results = $this->onceWithColumns(
            is_null($key) || $key === $column ? [$column] : [$column, $key],
            fn (): array => $this->runSelect(),
        );

        if ($results === []) {
            return new Collection;
        }

        $column = $this->resolvePluckColumn($column, $results[0]);

        $key = is_null($key) ? null : $this->resolvePluckColumn($key, $results[0]);

        $values = [];

        foreach ($results as $row) {
            if (is_null($key)) {
                $values[] = $row->{$column} ?? null;
            } else {
                $values[$row->{$key} ?? null] = $row->{$column} ?? null;
            }
        }

        return new Collection($values);
    }

    /**
     * Name the returned field a plucked column lands in.
     */
    protected function resolvePluckColumn(Expression|string $column, stdClass $row): string
    {
        // The server names an unaliased expression itself (`mean`, `count`):
        // whatever it chose, that is the first column after the timestamp.
        if ($column instanceof Expression) {
            $keys = array_values(array_diff(array_keys((array) $row), ['time']));

            return (string) ($keys[0] ?? 'time');
        }

        // An alias is the name the column comes back under; a type hint is not.
        if (stripos($column, ' as ') !== false) {
            return preg_split('/\s+as\s+/i', $column, 2)[1];
        }

        return Dialect::stripTypeHint($column);
    }

    /**
     * Determine if any rows exist for the current query.
     */
    public function exists(): bool
    {
        return $this->clone()->limit(1)->get()->isNotEmpty();
    }

    /**
     * Determine if no rows exist for the current query.
     */
    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    /**
     * Execute the given callback if no rows exist for the current query.
     */
    public function existsOr(Closure $callback): mixed
    {
        return $this->exists() ? true : $callback();
    }

    /**
     * Execute the given callback if rows exist for the current query.
     */
    public function doesntExistOr(Closure $callback): mixed
    {
        return $this->doesntExist() ? true : $callback();
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * InfluxQL counts a field: `count('value')`. The `*` default counts every
     * field, and the first field's count is the one returned.
     *
     * @param array<Expression|string>|Expression|string $columns
     */
    public function count(Expression|array|string $columns = '*'): int
    {
        return (int) $this->aggregate('count', Arr::wrap($columns));
    }

    /**
     * Retrieve the minimum value of a given column.
     */
    public function min(Expression|string $column): mixed
    {
        return $this->aggregate('min', [$column]);
    }

    /**
     * Retrieve the maximum value of a given column.
     */
    public function max(Expression|string $column): mixed
    {
        return $this->aggregate('max', [$column]);
    }

    /**
     * Retrieve the sum of the values of a given column.
     */
    public function sum(Expression|string $column): mixed
    {
        $result = $this->aggregate('sum', [$column]);

        return $result ?: 0;
    }

    /**
     * Retrieve the average of the values of a given column: InfluxQL's MEAN().
     */
    public function avg(Expression|string $column): mixed
    {
        return $this->aggregate('mean', [$column]);
    }

    /**
     * Alias for the "avg" method.
     */
    public function average(Expression|string $column): mixed
    {
        return $this->avg($column);
    }

    /**
     * Execute an aggregate function on the measurement.
     *
     * Any InfluxQL aggregate or selector works: `aggregate('last', ['value'])`,
     * `aggregate('percentile', ['value', new Expression('95')])`. A measurement
     * with no matching points yields no row at all, so null comes back rather
     * than a zero. InfluxDB 3 has no SAMPLE(), HOLT_WINTERS() or
     * technical-analysis functions, such as CHANDE_MOMENTUM_OSCILLATOR(): the
     * server refuses them, since the builder does not check function names.
     *
     * @param array<Expression|string> $columns
     */
    public function aggregate(string $function, array $columns = ['*']): mixed
    {
        $results = $this->cloneWithout(['columns'])
            ->cloneWithoutBindings(['select'])
            ->setAggregate($function, $columns)
            ->get($columns);

        if ($results->isEmpty()) {
            return null;
        }

        $row = array_change_key_case((array) $results[0]);

        if (array_key_exists('aggregate', $row)) {
            return $row['aggregate'];
        }

        // A wildcard aggregate carries no alias: the first column after the
        // timestamp is the result for the first field.
        unset($row['time']);

        return $row === [] ? null : $row[array_key_first($row)];
    }

    /**
     * Execute a numeric aggregate function on the measurement.
     *
     * @param array<Expression|string> $columns
     */
    public function numericAggregate(string $function, array $columns = ['*']): float|int
    {
        $result = $this->aggregate($function, $columns);

        // If there is no result, we can obviously just return 0 here. Next, we will check
        // if the result is an integer or float. If it is already one of these two data
        // types we can just return the result as-is, otherwise we will convert this.
        if (! $result) {
            return 0;
        }

        if (is_int($result) || is_float($result)) {
            return $result;
        }

        // If the result doesn't contain a decimal place, we will assume it is an int then
        // cast it to one. When it does we will cast it to a float since it needs to be
        // cast to the expected data type for the developers out of pure convenience.
        return ! str_contains((string) $result, '.')
            ? (int) $result
            : (float) $result;
    }

    /**
     * Set the aggregate property without running the query.
     *
     * @param array<Expression|string> $columns
     */
    protected function setAggregate(string $function, array $columns): static
    {
        $this->aggregate = ['function' => $function, 'columns' => $columns];

        if (empty($this->groups)) {
            $this->orders = null;

            $this->bindings['order'] = [];
        }

        return $this;
    }

    /**
     * Execute the given callback while selecting the given columns.
     *
     * After running the callback, the columns are reset to the original value.
     *
     * @template TResult
     *
     * @param array<Expression|string> $columns
     * @param callable(): TResult $callback
     * @return TResult
     */
    protected function onceWithColumns(array $columns, callable $callback): mixed
    {
        $original = $this->columns;

        if (is_null($original)) {
            $this->columns = $columns;
        }

        try {
            return $callback();
        } finally {
            $this->columns = $original;
        }
    }

    /**
     * Chunk the results of the query.
     *
     * InfluxQL returns points in time order whether or not ORDER BY is given,
     * so no ordering is enforced here as Hypervel's chunk() does.
     *
     * @param callable(Collection<int, stdClass>, int): mixed $callback
     *
     * @throws InvalidArgumentException
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $skip = $this->getOffset();
        $remaining = $this->getLimit();

        $page = 1;

        do {
            $offset = (($page - 1) * $count) + (int) $skip;

            $limit = is_null($remaining) ? $count : min($count, $remaining);

            if ($limit === 0) {
                break;
            }

            $results = $this->offset($offset)->limit($limit)->get();

            $countResults = $results->count();

            if ($countResults === 0) {
                break;
            }

            if (! is_null($remaining)) {
                $remaining = max($remaining - $countResults, 0);
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);

            ++$page;
        } while ($countResults === $count);

        return true;
    }

    /**
     * Delete the points the query's where clause selects.
     *
     * Compiles to InfluxQL's `DELETE FROM <measurement> WHERE ...`, which
     * takes conditions on time and tags only — the server refuses a field —
     * and reports no count: true means the server accepted the statement.
     * Without a time condition, every matching point is deleted, future ones
     * included. The measurement cannot name a database or retention policy,
     * and which policies the points go from depends on the version:
     *
     * - InfluxDB 1.x deletes from every retention policy of the database,
     *   not only the connection's;
     * - InfluxDB 2.x deletes from the database's default retention policy
     *   only, whichever the request names, so the statement is refused on a
     *   connection that addresses a retention policy;
     * - InfluxDB 3 has no DELETE, so the statement is always refused.
     *
     * @throws InvalidArgumentException when the measurement is missing or qualified
     * @throws RuntimeException when the connection's version cannot run the statement
     * @throws QueryException
     */
    public function delete(): bool
    {
        return $this->send(
            $this->grammar->compileDelete($this),
            Arr::flatten([$this->bindings['from'], $this->bindings['where']]),
            $this->connection->statement(...),
        );
    }

    /**
     * Get a new instance of the query builder.
     */
    public function newQuery(): static
    {
        // @phpstan-ignore new.static (A subclass that changes the constructor also overrides newQuery(), as Hypervel's builders do.)
        return new static($this->connection, $this->grammar);
    }

    /**
     * Create a raw expression.
     */
    public function raw(string|int|float $value): Expression
    {
        return $this->connection->raw($value);
    }

    /**
     * Get the "limit" value for the query or null if it's not set.
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get the "offset" value for the query or null if it's not set.
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * Get the current query value bindings in a flattened array.
     *
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return Arr::flatten($this->bindings);
    }

    /**
     * Get the raw array of bindings.
     *
     * @return array<string, list<mixed>>
     */
    public function getRawBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Set the bindings on the query builder.
     *
     * @param array<mixed> $bindings
     *
     * @throws InvalidArgumentException
     */
    public function setBindings(array $bindings, string $type = 'where'): static
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        $this->bindings[$type] = array_values(array_map($this->castBinding(...), $bindings));

        return $this;
    }

    /**
     * Add a binding to the query.
     *
     * @throws InvalidArgumentException
     */
    public function addBinding(mixed $value, string $type = 'where'): static
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_map(
                $this->castBinding(...),
                array_merge($this->bindings[$type], $value),
            ));
        } else {
            $this->bindings[$type][] = $this->castBinding($value);
        }

        return $this;
    }

    /**
     * Cast the given binding value.
     */
    public function castBinding(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return $value;
    }

    /**
     * Merge an array of bindings into our bindings.
     */
    public function mergeBindings(self $query): static
    {
        $this->bindings = array_merge_recursive($this->bindings, $query->bindings);

        return $this;
    }

    /**
     * Remove all of the expressions from a list of bindings.
     *
     * @param array<mixed> $bindings
     * @return list<mixed>
     */
    public function cleanBindings(array $bindings): array
    {
        return array_values(array_map(
            $this->castBinding(...),
            array_filter($bindings, fn (mixed $binding): bool => ! $binding instanceof Expression),
        ));
    }

    /**
     * Get a scalar type value from an unknown type of input.
     */
    protected function flattenValue(mixed $value): mixed
    {
        return is_array($value) ? Arr::first(Arr::flatten($value)) : $value;
    }

    /**
     * Get the InfluxQL connection instance.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get the query grammar instance.
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Clone the query.
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Clone the query without the given properties.
     *
     * @param list<string> $properties
     */
    public function cloneWithout(array $properties): static
    {
        $clone = $this->clone();

        foreach ($properties as $property) {
            $clone->{$property} = is_array($clone->{$property}) ? [] : null;
        }

        return $clone;
    }

    /**
     * Clone the query without the given bindings.
     *
     * @param list<string> $except
     */
    public function cloneWithoutBindings(array $except): static
    {
        $clone = $this->clone();

        foreach ($except as $type) {
            $clone->bindings[$type] = [];
        }

        return $clone;
    }

    /**
     * Handles dynamic "where" clauses to the query: `whereHost('web1')`.
     *
     * @param list<mixed> $parameters
     */
    public function dynamicWhere(string $method, array $parameters): static
    {
        $finder = substr($method, 5);

        $segments = preg_split(
            '/(And|Or)(?=[A-Z])/',
            $finder,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        // The connector variable will determine which connector will be used for the
        // query condition. We will change it as we come across new boolean values
        // in the dynamic method strings, which could contain a number of these.
        $connector = 'and';

        $index = 0;

        foreach ($segments as $segment) {
            // If the segment is not a boolean connector, we can assume it is a column's name
            // and we will add it to the query as a new constraint as a where clause, then
            // we can keep iterating through the dynamic method string's segments again.
            if ($segment !== 'And' && $segment !== 'Or') {
                $this->addDynamic($segment, $connector, $parameters, $index);

                ++$index;
            }

            // Otherwise, we will store the connector so we know how the next where clause we
            // find in the query should be connected to the previous ones, meaning we will
            // have the proper boolean connector to connect the next where clause found.
            else {
                $connector = $segment;
            }
        }

        return $this;
    }

    /**
     * Add a single dynamic where clause statement to the query.
     *
     * @param list<mixed> $parameters
     */
    protected function addDynamic(string $segment, string $connector, array $parameters, int $index): void
    {
        // Once we have parsed out the columns and formatted the boolean operators we
        // are ready to add it to this query as a where clause just like any other
        // clause on the query. Then we'll increment the parameter index values.
        $this->where(Str::snake($segment), '=', $parameters[$index], strtolower($connector));
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }

    /**
     * Handle dynamic method calls into the method.
     *
     * @param list<mixed> $parameters
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (preg_match('/^(?:or)?[wW]here(?:' . implode('|', self::UNSUPPORTED_WHERES) . ')$/', $method) === 1) {
            throw new BadMethodCallException(sprintf('%s::%s() is not supported: InfluxQL has no NOT, NULL, LIKE, date part, sub-select, JSON or full-text clause, and a relative date is a comparison on time, such as where(\'time\', \'<\', now()).', static::class, $method));
        }

        if (str_starts_with($method, 'where')) {
            return $this->dynamicWhere($method, $parameters);
        }

        throw new BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
    }
}
