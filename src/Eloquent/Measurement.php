<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Eloquent;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * A read-only Eloquent model of an InfluxDB measurement, on either of the package's drivers.
 *
 * Points are keyed by `time`, which is not unique: find() returns one of the points at that time.
 * save() and delete() throw, and every model write built on them: write through InfluxDB::writeApi().
 * A `datetime` cast on `time` breaks chunkById() and lazyById(), and reads an `epoch` integer as seconds.
 * On influxql, findMany() and whereKey() with several keys find nothing: InfluxQL cannot OR times.
 *
 * @see docs/eloquent.md#the-settings-a-time-series-needs
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
