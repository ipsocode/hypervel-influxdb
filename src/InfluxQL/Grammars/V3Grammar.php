<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars;

use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * Compiles a Builder into the InfluxQL InfluxDB 3 runs through its 1.x compatibility API.
 *
 * InfluxDB 3 reads InfluxQL as 1.x does, tz(), fill() and regular expressions
 * included, but its parser has no `SELECT ... INTO`, and it implements
 * neither SLIMIT and SOFFSET nor DELETE. So all four are refused, when the
 * statement compiles and before anything is sent. The server refuses SLIMIT
 * and SOFFSET even at 0, the clauses' no-op, so 0 compiles to nothing;
 * `soffset(null)` sets 0 as well. Version::V3 carries these rules.
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
