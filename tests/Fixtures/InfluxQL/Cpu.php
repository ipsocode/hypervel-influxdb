<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures\InfluxQL;

use Hypervel\Database\Eloquent\Relations\HasMany;
use Ipsocode\InfluxDB\Eloquent\Measurement;
use UnitEnum;

/**
 * A measurement read over InfluxQL, through the `influxql` database driver, with its time cast.
 */
class Cpu extends Measurement
{
    protected UnitEnum|string|null $connection = 'influxql';

    protected ?string $table = 'cpu';

    protected array $casts = ['time' => 'datetime'];

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
