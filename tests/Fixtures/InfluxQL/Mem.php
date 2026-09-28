<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures\InfluxQL;

use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

/**
 * A second measurement on the same InfluxQL connection, for Cpu's relation.
 */
class Mem extends Measurement
{
    protected UnitEnum|string|null $connection = 'influxql';

    protected ?string $table = 'mem';
}
