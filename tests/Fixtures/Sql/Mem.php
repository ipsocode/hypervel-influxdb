<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures\Sql;

use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

/**
 * A second measurement on the same InfluxDB 3 database, for Cpu's relation.
 */
class Mem extends Measurement
{
    protected UnitEnum|string|null $connection = 'influxdb';

    protected ?string $table = 'mem';
}
