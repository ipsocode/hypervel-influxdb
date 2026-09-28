<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures;

use Hypervel\Database\Eloquent\Model;
use UnitEnum;

/**
 * A read-only Eloquent model over an InfluxDB 3 table, through the `influxdb` database driver.
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
