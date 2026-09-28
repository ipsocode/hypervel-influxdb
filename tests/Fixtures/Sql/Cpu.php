<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures\Sql;

use Hypervel\Database\Eloquent\Relations\HasMany;
use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

/**
 * A measurement of an InfluxDB 3 table, through the `influxdb` database driver.
 */
class Cpu extends Measurement
{
    protected UnitEnum|string|null $connection = 'influxdb';

    protected ?string $table = 'cpu';

    /**
     * The memory points of the host.
     *
     * @return HasMany<Mem, $this>
     */
    public function mem(): HasMany
    {
        return $this->hasMany(Mem::class, 'host', 'host');
    }
}
