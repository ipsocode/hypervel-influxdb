<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use Ipsocode\InfluxDB\InfluxQL\Grammars\V1Grammar;

/**
 * An InfluxQL connection to InfluxDB 1.x, which runs the whole language.
 *
 * The upstream client speaks the 2.x API, which InfluxDB 1.8 and later serve
 * for compatibility: the token is `username:password`, or any non-empty value
 * when authentication is off, and the bucket is `database/retention-policy`.
 * InfluxQL splits the bucket at the slash the same way, so it reads what the
 * connection writes. A DELETE applies to every retention policy of the
 * database, not only the connection's.
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
