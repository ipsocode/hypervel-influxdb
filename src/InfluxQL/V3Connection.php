<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Grammars\V3Grammar;

/**
 * An InfluxQL connection to InfluxDB 3 Core or Enterprise, through its 1.x compatibility API.
 *
 * InfluxDB 3 has no retention policies and stores what the 2.x API writes under
 * the whole bucket name, slash included, so this connection reads the bucket unsplit.
 *
 * @see docs/configuration.md#connecting-to-influxdb-3
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
