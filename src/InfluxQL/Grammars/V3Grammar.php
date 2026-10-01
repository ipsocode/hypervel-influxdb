<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars;

use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * Compiles a Builder into the InfluxQL InfluxDB 3 runs through its 1.x compatibility API.
 *
 * The server refuses SLIMIT and SOFFSET even at 0, the clauses' no-op, so 0
 * compiles to nothing; `soffset(null)` sets 0 as well.
 *
 * @see docs/configuration.md#choosing-the-server-version
 */
class V3Grammar extends Grammar
{
    /**
     * Get the InfluxDB version the grammar compiles for.
     */
    protected function version(): Version
    {
        return Version::V3;
    }
}
