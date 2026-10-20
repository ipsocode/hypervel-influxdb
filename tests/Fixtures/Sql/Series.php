<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures\Sql;

use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

/**
 * A Measurement over the `series` table of the `sql` connection the InfluxDB 3 integration tests configure.
 */
class Series extends Measurement
{
    protected UnitEnum|string|null $connection = 'sql';

    protected ?string $table = 'series';
}
