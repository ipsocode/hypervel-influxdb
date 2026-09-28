<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars;

use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * Compiles a Builder into the InfluxQL InfluxDB 2.x runs through its 1.x compatibility API.
 *
 * 2.x reads as 1.x does, SLIMIT, SOFFSET and tz() included, but it has no
 * `SELECT ... INTO`, and its DELETE applies to the database's default
 * retention policy whichever one the request names. So `SELECT ... INTO` is
 * refused, as is a DELETE on a connection that addresses a retention policy,
 * when the statement compiles and before anything is sent: the server would
 * refuse the first, and could run the second on another bucket than the one
 * the connection reads. Version::V2 carries both rules.
 */
class V2Grammar extends Grammar
{
    /**
     * Get the InfluxDB version the grammar compiles for.
     */
    protected function version(): Version
    {
        return Version::V2;
    }
}
