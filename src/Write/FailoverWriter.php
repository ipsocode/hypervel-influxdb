<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use InfluxDB2\WriteApi;
use InfluxDB2\WriteType;

/**
 * A synchronous WriteApi whose writes fall back to other connections when its own cannot take them.
 *
 * What writeApi() returns for a connection whose `write` block names a
 * `fallback` without asking for batching. Its write() serialises points as the
 * parent's does and hands the line protocol to writeRaw(), which posts it
 * through the connection, with the client's retries, or, when the connection
 * cannot take it, through the first fallback that can.
 *
 * @see docs/writing.md#falling-back-to-other-connections
 */
class FailoverWriter extends WriteApi
{
    /**
     * The connections a write falls back to, with this writer's write options.
     */
    protected Failover $failover;

    /**
     * @param array<string, mixed> $options the connection's client options
     * @param Failover $failover the connections a write this connection cannot take falls back to
     * @param null|array<string, mixed> $writeOptions the client's write options, whose retry settings apply to each connection tried
     */
    public function __construct(array $options, Failover $failover, ?array $writeOptions = null)
    {
        $writeOptions['writeType'] = WriteType::SYNCHRONOUS;

        parent::__construct($options, $writeOptions);

        $this->failover = $failover->withWriteOptions($writeOptions);
    }

    /**
     * Post line protocol through the connection, or, when it cannot take it, through the first fallback that can.
     *
     * @throws FailoverException when no connection took the write
     * @throws \InfluxDB2\ApiException when the connection refuses the write for its own fault, such as a line it cannot parse
     */
    public function writeRaw(string $data, ?string $precision = null, ?string $bucket = null, ?string $org = null): void
    {
        $precision ??= $this->options['precision'] ?? null;
        $bucket ??= $this->options['bucket'] ?? null;
        $org ??= $this->options['org'] ?? null;

        $this->check('precision', $precision);
        $this->check('bucket', $bucket);
        $this->check('org', $org);

        $this->failover->write(
            $data,
            (string) $precision,
            (string) $bucket,
            (string) $org,
            fn () => parent::writeRaw($data, $precision, $bucket, $org),
        );
    }

    /**
     * Get the connections a write falls back to.
     */
    public function getFailover(): Failover
    {
        return $this->failover;
    }
}
