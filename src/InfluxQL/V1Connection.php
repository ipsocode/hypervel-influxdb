<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use Ipsocode\InfluxDB\InfluxQL\Grammars\V1Grammar;

/**
 * An InfluxQL connection to InfluxDB 1.x, which runs the whole language.
 *
 * InfluxDB 1.8 and later serve the 2.x API the InfluxDB client writes through,
 * with the bucket `database/retention-policy`, which InfluxQL splits the same way.
 *
 * @see docs/configuration.md#connecting-to-influxdb-1x
 */
class V1Connection extends Connection
{
    /**
     * Get the InfluxDB version the connection's server runs.
     */
    public function getVersion(): Version
    {
        return Version::V1;
    }

    /**
     * Get the grammar InfluxDB 1.x statements compile with.
     */
    protected function getDefaultQueryGrammar(): V1Grammar
    {
        return new V1Grammar;
    }
}
