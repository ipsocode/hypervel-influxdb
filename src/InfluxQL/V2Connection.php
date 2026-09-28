<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use Ipsocode\InfluxDB\InfluxQL\Grammars\V2Grammar;

/**
 * An InfluxQL connection to InfluxDB 2.x, through its 1.x compatibility API.
 *
 * InfluxDB 2.x keeps points in buckets, and answers /query by looking up the
 * bucket a database and retention policy are mapped to: a DBRP mapping. Since
 * 2.4, every bucket without an explicit mapping has a virtual one, derived
 * from its name the way this connection splits it: a bucket named
 * `telegraf/autogen` is database `telegraf` on retention policy `autogen`,
 * and one named `telegraf` is database `telegraf` on its default policy. A
 * bucket to address by another database name needs an explicit mapping on
 * the server (`influx v1 dbrp create`), whose database is set as
 * `influxql.database`.
 *
 * A virtual mapping is its database's default only for a bucket named
 * without a slash, which matters for DELETE: 2.x runs one on the default
 * policy alone, so V2Grammar refuses it on a connection that addresses a
 * retention policy. 2.x has no `SELECT ... INTO` either.
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
