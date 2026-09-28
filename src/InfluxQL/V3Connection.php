<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V3Grammar;

/**
 * An InfluxQL connection to InfluxDB 3 Core or Enterprise, through its 1.x compatibility API.
 *
 * InfluxDB 3 keeps points in databases and has no retention policies. What
 * the 2.x API writes, as the upstream client writes it, is stored in the
 * database named after the bucket, slash included: a bucket named
 * `telegraf/autogen` is database `telegraf/autogen`. So this connection reads
 * the bucket whole, or the database `influxql.database` names, and sends no
 * retention policy, which /query would ignore anyway unless the statement
 * names a database itself.
 *
 * InfluxDB 3 reads two more epochs than 1.x and 2.x, `d` and `w`, and runs
 * InfluxQL without `SELECT ... INTO`, SLIMIT, SOFFSET or DELETE, which
 * V3Grammar refuses. The same server also answers SQL, through the
 * `influxdb` database driver (Sql\SqlConnection).
 */
class V3Connection extends Connection
{
    /**
     * The timestamp precisions InfluxDB 3's /query endpoint accepts as its `epoch`.
     *
     * @var list<string>
     */
    protected const array EPOCHS = ['ns', 'u', 'µ', 'ms', 's', 'm', 'h', 'd', 'w'];

    /**
     * Get the InfluxDB version the connection's server runs.
     */
    public function getVersion(): Version
    {
        return Version::V3;
    }

    /**
     * Get the grammar InfluxDB 3 statements compile with.
     */
    protected function getDefaultQueryGrammar(): V3Grammar
    {
        return new V3Grammar;
    }

    /**
     * Resolve the database the statements are addressed to: the influxql block's, or else the bucket whole.
     *
     * The bucket is not split as it is for 1.x and 2.x, since InfluxDB 3
     * stores what the 2.x API writes under the full bucket name.
     *
     * @return array{?string, null}
     *
     * @throws InvalidArgumentException when the influxql block names a retention policy, which InfluxDB 3 does not have
     */
    protected function resolveDatabase(?string $database, ?string $retentionPolicy, ?string $bucket): array
    {
        if ($retentionPolicy !== null) {
            throw new InvalidArgumentException(sprintf(
                'InfluxDB connection [%s] has an influxql.retentionPolicy [%s], but InfluxDB 3 has no retention policies; name the database instead.',
                $this->name,
                $retentionPolicy,
            ));
        }

        return [$database ?? $bucket, null];
    }
}
