<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Eloquent;

use DateTimeInterface;
use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Support\LazyCollection;
use Ipsocode\InfluxDB\InfluxQL\Driver\Builder as DriverBuilder;
use Ipsocode\InfluxDB\InfluxQL\Series;
use LogicException;
use SortDirection;

/**
 * The Eloquent builder every Measurement queries through.
 *
 * A builder a measurement names with #[UseEloquentBuilder] must extend this one.
 *
 * @template TModel of Measurement
 *
 * @extends EloquentBuilder<TModel>
 *
 * @see src/docs/eloquent.md#custom-builders
 */
class Builder extends EloquentBuilder
{
    /**
     * Execute the query, its scopes applied, and return its series, as the server grouped the points.
     *
     * A query grouped by tags returns one series per combination of their
     * values, which carries the tags once rather than on each model.
     *
     * @return list<Series>
     *
     * @throws LogicException on a connection of another driver: series are InfluxQL's
     */
    public function series(): array
    {
        $query = $this->toBase();

        if (! $query instanceof DriverBuilder) {
            throw new LogicException(sprintf('series() needs the influxql driver, not [%s].', $query->getConnection()->getDriverName()));
        }

        return $query->series();
    }

    /**
     * Chunk the results by comparing the key, which is refused for `time`.
     *
     * @throws LogicException when paging on `time`, which several series can share
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, SortDirection|bool $descending = false): bool
    {
        $this->refuseTimeKeyedPaging($column, 'chunkById()');

        return parent::orderedChunkById($count, $callback, $column, $alias, $descending);
    }

    /**
     * Paginate the query into a cursor paginator, refused on SQL when `time` alone orders it.
     *
     * @param array<string>|string $columns
     * @return CursorPaginator<int, TModel>
     *
     * @throws LogicException when only `time` orders the query on the influxdb driver
     */
    public function cursorPaginate(?int $perPage = null, array|string $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        if (! $this->query instanceof DriverBuilder && $this->ordersByTimeAlone()) {
            throw new LogicException('cursorPaginate() ordered by time alone skips the points that share the timestamp a page ends on; order by another column too, or page with chunk() or lazy().');
        }

        return parent::cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Add a where clause on the primary key, keeping a date as the instant it names.
     *
     * Hypervel casts a string key to a string, which writes a Carbon without
     * its fraction and offset, and throws on a DateTimeImmutable. A date is
     * left to the connection, which formats its bindings.
     */
    public function whereKey(mixed $id, string $boolean = 'and', bool $not = false): static
    {
        if ($id instanceof DateTimeInterface) {
            $this->query->where($this->model->getQualifiedKeyName(), $not ? '<>' : '=', $id, $boolean);

            return $this;
        }

        return parent::whereKey($id, $boolean, $not);
    }

    /**
     * Get the selected value through the model's attribute accessors.
     *
     * InfluxQL returns every point with its time first, so the value of an
     * expression is the first attribute after `time`, under whatever name
     * the server gave it. SQL returns the expression on its own.
     */
    protected function getValueFromModel(Model $model, Expression|string $column): mixed
    {
        if (! $column instanceof Expression) {
            return parent::getValueFromModel($model, $column);
        }

        $attributes = $model->getAttributes();

        if (count($attributes) > 1) {
            unset($attributes['time']);
        }

        return $model->{(string) array_key_first($attributes)};
    }

    /**
     * Query lazily by comparing the key, which is refused for `time`.
     *
     * @return LazyCollection<int, TModel>
     *
     * @throws LogicException when paging on `time`, which several series can share
     */
    protected function orderedLazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null, SortDirection|bool $descending = false): LazyCollection
    {
        $this->refuseTimeKeyedPaging($column, 'lazyById()');

        return parent::orderedLazyById($chunkSize, $column, $alias, $descending);
    }

    /**
     * Refuse to page on `time`, which several series can share, so the next page would skip points.
     *
     * @throws LogicException
     */
    protected function refuseTimeKeyedPaging(?string $column, string $method): void
    {
        if ($column === null || $column === $this->model->getKeyName()) {
            throw new LogicException(sprintf('%s pages on time, which several series can share, so it would skip points; page with chunk() or lazy().', $method));
        }
    }

    /**
     * Determine whether `time`, or nothing, is all the query is ordered by.
     */
    protected function ordersByTimeAlone(): bool
    {
        foreach ($this->query->orders ?? [] as $order) {
            $column = $order['column'] ?? null;

            if (! is_string($column) || ($column !== 'time' && ! str_ends_with($column, '.time'))) {
                return false;
            }
        }

        return true;
    }
}
