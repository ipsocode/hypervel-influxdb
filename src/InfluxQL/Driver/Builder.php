<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Driver;

use Closure;
use DatePeriod;
use DateTimeZone;
use Exception;
use Hypervel\Contracts\Database\Query\ConditionExpression;
use Hypervel\Contracts\Database\Query\Expression as ExpressionContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\MultipleRecordsFoundException;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\RecordsNotFoundException;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Dialect;
use Ipsocode\InfluxDB\InfluxQL\Regex;
use Ipsocode\InfluxDB\InfluxQL\Series;
use RuntimeException;
use SortDirection;
use stdClass;

/**
 * Hypervel's query builder, carrying the clauses only InfluxQL has, for the `influxql` database driver.
 *
 * It holds Hypervel's builder to what InfluxQL can say: a column qualified by the measurement,
 * as Eloquent qualifies `cpu.time`, is taken unqualified, and what InfluxQL cannot express is
 * refused here or by the grammar as the statement compiles, before anything is sent.
 *
 * @see docs/influxql-driver.md#what-to-expect
 */
class Builder extends QueryBuilder
{
    /**
     * All of the available clause operators: InfluxQL's.
     *
     * @var list<string>
     */
    public array $operators = Dialect::OPERATORS;

    /**
     * The measurement a `SELECT ... INTO` writes to.
     */
    public ExpressionContract|string|null $into = null;

    /**
     * The fill() option for a GROUP BY time() query.
     */
    public string|int|float|null $fill = null;

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
     * Set the columns to be selected, stripped of the measurement Eloquent qualifies them with (`cpu.time`).
     */
    public function select(mixed $columns = ['*']): static
    {
        return parent::select($this->unqualifyColumns(is_array($columns) ? $columns : func_get_args()));
    }

    /**
     * Add a new select column to the query, stripped of the measurement Eloquent qualifies it with (`cpu.time`).
     */
    public function addSelect(mixed $column): static
    {
        return parent::addSelect($this->unqualifyColumns(is_array($column) ? $column : func_get_args()));
    }

    /**
     * Refuse a sub-select among the columns: InfluxQL has none, so Eloquent's withCount() and withSum() fail here.
     *
     * @throws InvalidArgumentException
     */
    public function selectSub(Closure|QueryBuilder|EloquentBuilder|Relation|string $query, string $as): static
    {
        throw new InvalidArgumentException('InfluxQL has no sub-select among the columns; query the other measurement on its own.');
    }

    /**
     * Select from a subquery, which InfluxQL writes without a name.
     *
     * The name is optional, so that from() takes a Closure on its own.
     *
     * @throws InvalidArgumentException when the subquery is given a name
     */
    public function fromSub(Closure|QueryBuilder|EloquentBuilder|Relation|string $query, ?string $as = null): static
    {
        if ($as !== null && $as !== '') {
            throw new InvalidArgumentException(sprintf('InfluxQL cannot name a subquery; select from it without [%s].', $as));
        }

        [$query, $bindings] = $this->createSub($query);

        return $this->fromRaw('(' . $query . ')', $bindings);
    }

    /**
     * Set the measurement a `SELECT ... INTO` writes its result to.
     *
     * @throws RuntimeException on InfluxDB 2.x and 3, when the statement compiles
     */
    public function into(ExpressionContract|string $measurement): static
    {
        $this->into = $measurement;

        return $this;
    }

    /**
     * Group the points into time windows: `GROUP BY time(10m)`, or `time(1h, 15m)` with an offset.
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
     * Set the maximum number of series to return: InfluxQL's SLIMIT.
     *
     * @throws RuntimeException on InfluxDB 3 for any value but 0, when the statement compiles
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
     * @throws RuntimeException on InfluxDB 3 for any value but 0, when the statement compiles
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
     * Add a basic where clause to the query.
     *
     * An operator InfluxQL lacks is refused when a value follows: `where('host', 'like', 'web%')` would otherwise be `"host" = 'like'`.
     * A string value with `=~` or `!~` is taken as a regular expression; a
     * Regex value turns `=` and `!=` into their regex forms.
     *
     * @throws InvalidArgumentException for an operator InfluxQL does not have, or one that cannot take a regular expression
     */
    public function where(Closure|QueryBuilder|EloquentBuilder|Relation|ExpressionContract|array|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof ConditionExpression || is_array($column) || $column instanceof Closure) {
            return parent::where($column, $operator, $value, $boolean);
        }

        [$value, $operator] = $this->prepareValueAndOperator($value, $operator, func_num_args() === 2);

        if ($this->invalidOperator($operator)) {
            $this->refuseUnsupportedOperator($operator, $value);

            [$value, $operator] = [$operator, '='];
        }

        [$value, $operator] = $this->prepareRegex($value, $operator);

        return parent::where($this->unqualifyColumn($column), $operator, $value, $boolean);
    }

    /**
     * Add a "where" clause comparing two columns to the query.
     *
     * @throws InvalidArgumentException for an operator InfluxQL does not have
     */
    public function whereColumn(ExpressionContract|string|array $first, ExpressionContract|string|null $operator = null, ExpressionContract|string|null $second = null, string $boolean = 'and'): static
    {
        if (is_array($first)) {
            return parent::whereColumn($first, $operator, $second, $boolean);
        }

        if ($this->invalidOperator($operator)) {
            $this->refuseUnsupportedOperator($operator, $second);

            [$second, $operator] = [$operator, '='];
        }

        return parent::whereColumn($this->unqualifyColumn($first), $operator, $this->unqualifyColumn($second), $boolean);
    }

    /**
     * Add a raw where clause to the query.
     *
     * A single binding may be passed bare. It is wrapped rather than cast, as
     * a cast would turn a Regex or a date into the array of its properties.
     */
    public function whereRaw(ExpressionContract|string $sql, mixed $bindings = [], string $boolean = 'and'): static
    {
        return parent::whereRaw($sql, Arr::wrap($bindings), $boolean);
    }

    /**
     * Add a "where in" clause to the query.
     *
     * InfluxQL has no IN: the grammar compiles one equality per value, ORed.
     *
     * @throws InvalidArgumentException for a sub-select
     */
    public function whereIn(ExpressionContract|string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        if ($this->isQueryable($values)) {
            throw new InvalidArgumentException('InfluxQL cannot compare a column with a sub-select; pass whereIn() the values themselves.');
        }

        return parent::whereIn($this->unqualifyColumn($column), $values, $boolean, $not);
    }

    /**
     * Add a where between statement to the query.
     *
     * InfluxQL has no BETWEEN: the grammar compiles two inclusive comparisons of the
     * first two bounds. A DatePeriod, such as a CarbonPeriod, gives its start and end.
     *
     * @throws InvalidArgumentException for a sub-select, or fewer than two bounds
     */
    public function whereBetween(Closure|QueryBuilder|EloquentBuilder|Relation|ExpressionContract|string $column, iterable $values, string $boolean = 'and', bool $not = false): static
    {
        if ($this->isQueryable($column)) {
            throw new InvalidArgumentException('InfluxQL cannot compare a sub-select; pass whereBetween() a column.');
        }

        if ($values instanceof DatePeriod) {
            $values = $this->resolveDatePeriodBounds($values);
        }

        $values = array_values(is_array($values) ? $values : iterator_to_array($values, false));

        if (count($values) < 2) {
            throw new InvalidArgumentException('whereBetween needs a lower and an upper bound.');
        }

        return parent::whereBetween($this->unqualifyColumn($column), array_slice($values, 0, 2), $boolean, $not);
    }

    /**
     * Add a "where null" clause to the query, unless it can say nothing more.
     *
     * An Eloquent relation adds `fk is not null`, which InfluxQL cannot write, beside
     * `fk = key`. A point matching the `=` has the value, so a not-null check on columns
     * the query compares with `=`, both ANDed, is dropped; the grammar refuses any other.
     */
    public function whereNull(string|array|ExpressionContract $columns, string $boolean = 'and', bool $not = false): static
    {
        $columns = array_map($this->unqualifyColumn(...), Arr::wrap($columns));

        if ($not && $boolean === 'and' && array_all($columns, $this->comparesForEquality(...))) {
            return $this;
        }

        return parent::whereNull($columns, $boolean, $not);
    }

    /**
     * Add an "order by" clause to the query.
     *
     * InfluxQL sorts by time only, so the column is `time`, qualified or
     * not, unless a raw expression is given.
     *
     * @throws InvalidArgumentException for another column, or a sub-select
     */
    public function orderBy(Closure|QueryBuilder|EloquentBuilder|Relation|ExpressionContract|string $column, SortDirection|string $direction = SortDirection::Ascending): static
    {
        $column = $this->unqualifyColumn($column);

        if ($this->isQueryable($column) || (is_string($column) && strtolower($column) !== 'time')) {
            throw new InvalidArgumentException('InfluxQL sorts by time only; orderBy() takes "time" or a raw expression.');
        }

        return parent::orderBy($column, $direction);
    }

    /**
     * Add an "order by" clause for the newest points first.
     */
    public function latest(Closure|QueryBuilder|EloquentBuilder|Relation|ExpressionContract|string $column = 'time'): static
    {
        return $this->orderBy($column, SortDirection::Descending);
    }

    /**
     * Add an "order by" clause for the oldest points first.
     */
    public function oldest(Closure|QueryBuilder|EloquentBuilder|Relation|ExpressionContract|string $column = 'time'): static
    {
        return $this->orderBy($column, SortDirection::Ascending);
    }

    /**
     * Refuse an order by a sequence of values: InfluxQL has no CASE expression to compile it to.
     *
     * @throws InvalidArgumentException
     */
    public function inOrderOf(ExpressionContract|string $column, Arrayable|array $values): static
    {
        throw new InvalidArgumentException('InfluxQL sorts by time only; it cannot order by a sequence of values.');
    }

    /**
     * Get a single column's value from the first result of a query.
     */
    public function value(ExpressionContract|string $column): mixed
    {
        return $this->withoutFetchUsing(fn (): mixed => $this->valueOf($this->first([$column]), $column));
    }

    /**
     * Get a single expression value from the first result of a query: the first field after the time.
     */
    public function rawValue(string $expression, array $bindings = []): mixed
    {
        return $this->withoutFetchUsing(fn (): mixed => $this->valueOf($this->selectRaw($expression, $bindings)->first(), null));
    }

    /**
     * Get a single column's value from the first result of a query if it's the sole matching record.
     *
     * @throws RecordsNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function soleValue(ExpressionContract|string $column): mixed
    {
        return $this->withoutFetchUsing(fn (): mixed => $this->valueOf($this->sole([$column]), $column));
    }

    /**
     * Execute the query and return its series, as the server grouped the points, rather than rows.
     *
     * @return list<Series>
     */
    public function series(): array
    {
        /** @var Connection $connection */
        $connection = $this->connection;

        return $connection->series($this->toSql(), $this->getBindings());
    }

    /**
     * Get the count of the total records for the paginator.
     *
     * `count(*)` counts each field on its own; the first field's count is taken, as aggregate() does.
     *
     * @param array<ExpressionContract|string> $columns
     * @return int<0, max>
     */
    public function getCountForPagination(array $columns = ['*']): int
    {
        $results = $this->withoutFetchUsing(function () use ($columns): array {
            $query = $this;

            // Count preparation needs the completed clauses without consuming the page's callbacks.
            if ($this->beforeQueryCallbacks !== []) {
                $query = $this->clone();
                $query->applyBeforeQueryCallbacks();
            }

            return $query->runPaginationCountQuery($columns);
        });

        return (int) $this->valueOf($results[0] ?? null, 'aggregate');
    }

    /**
     * Get column values and the returned field name for Eloquent attribute conversion.
     *
     * The server names an unaliased expression itself: its values are the first column after `time`.
     *
     * @return array{Collection<array-key, mixed>, null|string}
     */
    public function pluckWithColumn(ExpressionContract|string $column, ?string $key = null): array
    {
        if (is_string($column)) {
            return parent::pluckWithColumn($column, $key);
        }

        return $this->withoutFetchUsing(function () use ($column, $key): array {
            $rows = $this->onceWithColumns(
                is_null($key) ? [$column] : [$column, $key],
                fn (): array => $this->processor->processSelect($this, $this->runSelect()),
            );

            if ($rows === []) {
                return [new Collection, null];
            }

            $fields = (array) $rows[0];
            unset($fields['time']);
            $field = (string) array_key_first($fields);

            return [$this->applyAfterQueryCallbacks($this->pluckFromObjectColumn($rows, $field, $this->stripTableForPluck($key))), $field];
        });
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * InfluxQL has no EXISTS: the grammar asks for one point, and the query
     * has results when that point comes back.
     */
    public function exists(): bool
    {
        $this->applyBeforeQueryCallbacks();

        return $this->connection->select($this->grammar->compileExists($this), $this->getBindings(), ! $this->useWritePdo) !== [];
    }

    /**
     * Execute an aggregate function on the database.
     *
     * The result is the column the grammar names `aggregate`, or else the first after `time`:
     * InfluxQL names each count of `count(*)` after its field.
     *
     * @param array<ExpressionContract|string> $columns
     */
    public function aggregate(string $function, array $columns = ['*']): mixed
    {
        return $this->withoutFetchUsing(fn (): mixed => $this->valueOf(
            $this->cloneWithout(['columns'])->cloneWithoutBindings(['select'])->setAggregate($function, $columns)->get($columns)->first(),
            'aggregate',
        ));
    }

    /**
     * Delete the points the query's where clause selects.
     *
     * The where takes time and tags only, and InfluxQL reports no count, so 0 comes back.
     *
     * @throws InvalidArgumentException for an id, which a point does not have
     * @throws RuntimeException on InfluxDB 3, on 2.x when the connection addresses a retention policy, or for a join, limit, offset, slimit or soffset
     *
     * @see docs/influxql.md#deleting-points
     */
    public function delete(mixed $id = null): int
    {
        if (! is_null($id)) {
            throw new InvalidArgumentException('A point has no id to delete it by; constrain the query with where() instead.');
        }

        return parent::delete();
    }

    /**
     * Run a pagination count query.
     *
     * @param array<ExpressionContract|string> $columns
     * @return array<mixed>
     *
     * @throws RuntimeException for a grouped query, whose groups InfluxQL cannot count
     */
    protected function runPaginationCountQuery(array $columns = ['*']): array
    {
        if ($this->groups) {
            throw new RuntimeException('InfluxQL cannot count the groups of a grouped query; pass paginate() its total instead.');
        }

        return parent::runPaginationCountQuery($columns);
    }

    /**
     * Order the query by time when it has no order of its own.
     *
     * InfluxQL returns points in time order anyway, but cursorPaginate() takes its
     * cursor from the columns the query is ordered by.
     */
    protected function enforceOrderBy(): void
    {
        if (empty($this->orders)) {
            $this->orderBy('time');
        }
    }

    /**
     * Strip a column down to the name it is returned as: without the measurement, the alias it is given or its type hint.
     */
    protected function stripTableForPluck(ExpressionContract|string|null $column): ?string
    {
        if (is_null($column)) {
            return null;
        }

        $column = $column instanceof ExpressionContract ? (string) $this->grammar->getValue($column) : $column;

        if (stripos($column, ' as ') !== false) {
            return preg_split('/\s+as\s+/i', $column, 2)[1];
        }

        return Dialect::stripTypeHint($this->unqualify($column));
    }

    /**
     * Set the aggregate property without running the query, on the columns unqualified.
     *
     * @param array<ExpressionContract|string> $columns
     */
    protected function setAggregate(string $function, array $columns): static
    {
        return parent::setAggregate($function, $this->unqualifyColumns($columns));
    }

    /**
     * Execute the given callback while selecting the given columns, unqualified.
     *
     * get(), first(), value(), pluck() and paginate() select through here.
     *
     * @template TResult
     *
     * @param array<ExpressionContract|string> $columns
     * @param callable(): TResult $callback
     * @return TResult
     */
    protected function onceWithColumns(array $columns, callable $callback): mixed
    {
        return parent::onceWithColumns($this->unqualifyColumns($columns), $callback);
    }

    /**
     * Refuse an operator InfluxQL does not have when a value follows it.
     *
     * An operator InfluxQL lacks is refused when a value follows: `where('host', 'like', 'web%')` would otherwise be `"host" = 'like'`.
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
     * Determine whether the query already compares the column with a value by `=`, ANDed.
     */
    protected function comparesForEquality(mixed $column): bool
    {
        foreach ($this->wheres as $where) {
            if ($where['type'] === 'Basic' && $where['boolean'] === 'and' && $where['operator'] === '=' && $where['column'] === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the value a column is returned with in a row, or null for no row.
     *
     * The server names an unaliased column itself: the value is the first column after `time`.
     */
    protected function valueOf(?stdClass $row, ExpressionContract|string|null $column): mixed
    {
        $fields = (array) $row;
        $field = is_string($column) ? $this->stripTableForPluck($column) : null;

        if ($field !== null && array_key_exists($field, $fields)) {
            return $fields[$field];
        }

        unset($fields['time']);

        return $fields === [] ? null : array_first($fields);
    }

    /**
     * Strip the measurement a column is qualified by: `cpu.time` becomes `time`.
     *
     * InfluxQL would read `"cpu.time"` as a field of that name. Only getFromAlias() is
     * stripped (`telegraf.autogen.cpu` when qualified), so another measurement's column
     * is left as it is, and a field whose own name starts with `cpu.` must be an expression.
     */
    protected function unqualify(string $column): string
    {
        $alias = $this->getFromAlias();

        return $alias !== null && str_starts_with($column, $alias . '.') ? substr($column, strlen($alias) + 1) : $column;
    }

    /**
     * Strip the measurement from a column given as a string, and leave anything else as it is.
     */
    protected function unqualifyColumn(mixed $column): mixed
    {
        return is_string($column) ? $this->unqualify($column) : $column;
    }

    /**
     * Strip the measurement from each column given as a string, keeping the keys.
     *
     * @param array<mixed> $columns
     * @return array<mixed>
     */
    protected function unqualifyColumns(array $columns): array
    {
        return array_map($this->unqualifyColumn(...), $columns);
    }
}
