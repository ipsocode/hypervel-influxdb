<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars;

use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * Compiles a Builder into the InfluxQL InfluxDB 1.x runs: the whole language.
 *
 * `SELECT ... INTO`, SLIMIT, SOFFSET and DELETE all compile, since Version::V1
 * runs every one of them. A DELETE applies to every retention policy of the
 * database, whichever one the connection addresses.
 */
class V1Grammar extends Grammar
{
    /**
     * Get the InfluxDB version the grammar compiles for.
     */
    protected function version(): Version
    {
        return Version::V1;
    }
}
