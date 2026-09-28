<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Eloquent;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\InfluxDB\InfluxQL\Driver\Builder as DriverBuilder;
use Ipsocode\InfluxDB\InfluxQL\Series;
use LogicException;

/**
 * The Eloquent builder every Measurement queries through.
 *
 * A measurement that names its own builder with #[UseEloquentBuilder]
 * extends this one.
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
}
