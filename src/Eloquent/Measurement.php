<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Eloquent;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * A read-only Eloquent model of an InfluxDB measurement.
 *
 * Extend it with the database connection and the measurement it reads, on
 * either of the package's database drivers:
 *
 *     class Cpu extends Measurement
 *     {
 *         protected UnitEnum|string|null $connection = 'influxdb';
 *
 *         protected ?string $table = 'cpu';
 *     }
 *
 * It makes the settings a time series needs. A point is keyed by its `time`,
 * a timestamp rather than an incrementing integer, so find() and whereKey()
 * compare `time`, and latest() and oldest() order by it. No timestamps are
 * kept, since nothing is written, and nothing is guarded, since every
 * attribute comes from the server. `time` is not unique: several series can
 * each hold a point at the same time, and find() returns one of them.
 *
 * InfluxDB takes points through its write API rather than a query, so the
 * model is read-only: save() and delete() throw, and with them create(),
 * push(), destroy(), forceDelete(), their quiet variants and update() on a
 * model read from the server. Write points through InfluxDB::writeApi()
 * instead. A write the builder sends itself, such as
 * `Cpu::query()->update()`, is the connection's to refuse.
 *
 * `time` is not cast. A `datetime` cast makes the key an object, which
 * chunkById() and lazyById() cannot page on, and reads an integer as
 * seconds, whatever the precision a connection's `epoch` returns. Cast
 * `time` only on a connection without `epoch`, and page with chunk(),
 * lazy() or cursorPaginate(). On the influxql driver, chunkById() and
 * lazyById() are refused however `time` is cast: their first page asks for
 * a key that is not null, which InfluxQL cannot say. Nor can it OR two
 * times, so findMany() and whereKey() with several keys find no points.
 *
 * series(), on the influxql driver, returns the points as the server
 * grouped them rather than as models.
 */
abstract class Measurement extends Model
{
    /**
     * The name of the "created at" column, which latest() and oldest() order by.
     */
    public const ?string CREATED_AT = 'time';

    /**
     * The name of the "updated at" column: none, since a point is never updated.
     */
    public const ?string UPDATED_AT = null;

    /**
     * The primary key for the model.
     */
    protected string $primaryKey = 'time';

    /**
     * The "type" of the primary key: a timestamp, as the server returns it.
     */
    protected string $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public bool $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     */
    public bool $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>
     */
    protected array $guarded = [];

    /**
     * Refuse to save the model: InfluxDB takes points through its write API.
     *
     * @param array<string, mixed> $options
     *
     * @throws LogicException
     */
    public function save(array $options = []): bool
    {
        $this->refuseWrites();
    }

    /**
     * Refuse to delete the model: InfluxDB takes points through its write API.
     *
     * @throws LogicException
     */
    public function delete(): int|bool|null
    {
        $this->refuseWrites();
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * A measurement that names its own builder with #[UseEloquentBuilder]
     * gets that one, which has to extend Eloquent\Builder, as Hypervel's
     * nested set models require of theirs.
     *
     * @throws LogicException when the builder the model names does not extend Eloquent\Builder
     */
    public function newEloquentBuilder(QueryBuilder $query): Builder
    {
        $builderClass = static::$resolvedBuilderClasses[static::class]
            ??= $this->resolveCustomBuilderClass();

        if ($builderClass === false) {
            return new Builder($query);
        }

        if (! is_subclass_of($builderClass, Builder::class)) {
            throw new LogicException(sprintf(
                'Measurement [%s] must use a builder that extends [%s].',
                static::class,
                Builder::class,
            ));
        }

        /** @var Builder $builder */
        $builder = new $builderClass($query);

        return $builder;
    }

    /**
     * Refuse a write, before anything is sent.
     *
     * @throws LogicException
     */
    private function refuseWrites(): never
    {
        throw new LogicException('InfluxDB models are read-only; write points through InfluxDB::writeApi().');
    }
}
