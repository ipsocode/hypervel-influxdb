<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL\Grammars;

use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * Compiles a Builder into the InfluxQL InfluxDB 1.x runs: the whole language.
 *
 * @see docs/configuration.md#choosing-the-server-version
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
