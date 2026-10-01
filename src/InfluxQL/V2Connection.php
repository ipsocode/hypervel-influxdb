<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use Ipsocode\InfluxDB\InfluxQL\Grammars\V2Grammar;

/**
 * An InfluxQL connection to InfluxDB 2.x, through its 1.x compatibility API.
 *
 * The server reads the bucket a database and retention policy are mapped to; since
 * 2.4 a bucket without an explicit mapping has a virtual one, derived from its name
 * the way this connection splits it.
 *
 * @see docs/configuration.md#connecting-to-influxdb-2x
 */
class V2Connection extends Connection
{
    /**
     * Get the InfluxDB version the connection's server runs.
     */
    public function getVersion(): Version
    {
        return Version::V2;
    }

    /**
     * Get the grammar InfluxDB 2.x statements compile with.
     */
    protected function getDefaultQueryGrammar(): V2Grammar
    {
        return new V2Grammar;
    }
}
