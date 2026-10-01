<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures;

use Hypervel\Database\Eloquent\Model;
use UnitEnum;

/**
 * A plain Eloquent model, not a Measurement, over the InfluxDB 3 `cpu` table on the `influxdb` driver.
 */
class Cpu extends Model
{
    protected UnitEnum|string|null $connection = 'influxdb';

    protected ?string $table = 'cpu';

    public bool $timestamps = false;

    protected array $guarded = [];

    protected array $casts = [
        'time' => 'datetime',
    ];
}
