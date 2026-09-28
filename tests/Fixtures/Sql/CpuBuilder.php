<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures\Sql;

use Ipsocode\InfluxDB\Eloquent\Builder;

/**
 * A measurement's own Eloquent builder, which extends the package's.
 */
class CpuBuilder extends Builder
{
    /**
     * Scope the query to one host.
     */
    public function whereHost(string $host): static
    {
        return $this->where('host', $host);
    }
}
