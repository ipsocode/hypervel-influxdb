<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use InfluxDB2\WriteType;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * When a BatchingWriter sends a batch, and what it does when it cannot.
 *
 * A batch is sent once it holds `batchSize` lines of line protocol or
 * `batchSizeMb` megabytes, whichever comes first, and at the latest
 * `flushInterval` seconds after its first point. Left unset, the size limits
 * are InfluxData's advice for the connection's version:
 *
 * - v1: 5,000 lines, the low end of the 5,000 to 10,000 points per batch
 *   InfluxDB 1.x's glossary recommends, or 25 MB, the largest request body
 *   1.x accepts by default (`max-body-size`);
 * - v2: 5,000 lines, the optimal batch size InfluxDB 2.x's write guide gives,
 *   or 50 MB, the largest request InfluxDB Cloud accepts (OSS sets no limit);
 * - v3: 10,000 lines or 10 MB, "whichever threshold is met first", as
 *   InfluxDB 3's write guide puts it, which is also within its default
 *   `max-http-request-size`.
 *
 * A megabyte is 1,000,000 bytes, so a limit stays under a server's limit
 * whichever way the server counts it.
 *
 * @see https://docs.influxdata.com/influxdb/v1/concepts/glossary/#batch
 * @see https://docs.influxdata.com/influxdb/v1/administration/config/#max-body-size
 * @see https://docs.influxdata.com/influxdb/v2/write-data/best-practices/optimize-writes/#batch-writes
 * @see https://docs.influxdata.com/influxdb/cloud/account-management/limits/
 * @see https://docs.influxdata.com/influxdb3/core/write-data/best-practices/optimize-writes/#batch-writes
 */
final class BatchOptions
{
    /**
     * Past `maxBuffered`, write() sends the buffer itself before it returns.
     */
    public const string FLUSH = 'flush';

    /**
     * Past `maxBuffered`, write() throws a BufferFullException and keeps nothing.
     */
    public const string REFUSE = 'refuse';

    /**
     * The seconds a batch waits to fill when `flushInterval` is not set.
     */
    public const float DEFAULT_FLUSH_INTERVAL = 1.0;

    /**
     * The batches' worth of points held when `maxBuffered` is not set.
     */
    public const int DEFAULT_BUFFERED_BATCHES = 10;

    /**
     * The retries a failed batch gets when `maxRetries` is not set: fewer than upstream's 5.
     */
    public const int DEFAULT_MAX_RETRIES = 3;

    /**
     * @param int $batchSize the lines of line protocol a batch is sent at
     * @param int $batchBytes the bytes a batch is sent at, and no other write makes it outgrow
     * @param float $flushInterval the seconds after its first point a batch is sent at the latest
     * @param int $maxBuffered the points held at most, buffered or being sent, before `overflow` applies
     * @param string $overflow what write() does past `maxBuffered`: FLUSH or REFUSE
     * @param null|callable|class-string $onFailure called with the Batch and the BatchWriteException of each batch that could not be written
     */
    public function __construct(
        public readonly int $batchSize,
        public readonly int $batchBytes,
        public readonly float $flushInterval,
        public readonly int $maxBuffered,
        public readonly string $overflow = self::FLUSH,
        public readonly mixed $onFailure = null,
    ) {
    }

    /**
     * Determine whether a connection's write options ask for batching.
     *
     * Upstream's `writeType` stays the switch: WriteType::BATCHING turns it
     * on, and anything else, or nothing, leaves writes synchronous.
     *
     * @param null|array<string, mixed> $writeOptions
     */
    public static function batching(?array $writeOptions): bool
    {
        return is_numeric($writeOptions['writeType'] ?? null) && (int) $writeOptions['writeType'] === WriteType::BATCHING;
    }

    /**
     * Resolve the batching options of a connection's write options, with its version's defaults.
     *
     * A key left out or null takes its default, and numbers may be given as
     * the numeric strings env() reads.
     *
     * @param array<string, mixed> $writeOptions the connection's `write` block
     * @param string $connection the connection's name, for the message
     *
     * @throws InvalidArgumentException when an option is out of range or of the wrong type
     */
    public static function fromConfig(array $writeOptions, Version $version, string $connection): self
    {
        [$lines, $megabytes] = match ($version) {
            Version::V1 => [5_000, 25],
            Version::V2 => [5_000, 50],
            Version::V3 => [10_000, 10],
        };

        $batchSize = self::positiveInteger($writeOptions, 'batchSize', $lines, $connection);
        $megabytes = self::positiveNumber($writeOptions, 'batchSizeMb', $megabytes, $connection);
        $overflow = $writeOptions['overflow'] ?? self::FLUSH;
        $onFailure = $writeOptions['onFailure'] ?? null;

        if ($overflow !== self::FLUSH && $overflow !== self::REFUSE) {
            throw self::invalid($connection, 'overflow', $overflow, sprintf('%s or %s', self::FLUSH, self::REFUSE));
        }

        if ($onFailure !== null && ! is_callable($onFailure) && ! (is_string($onFailure) && method_exists($onFailure, '__invoke'))) {
            throw self::invalid($connection, 'onFailure', $onFailure, 'a callable or the name of an invokable class');
        }

        return new self(
            $batchSize,
            max(1, (int) round($megabytes * 1_000_000)),
            self::positiveNumber($writeOptions, 'flushInterval', self::DEFAULT_FLUSH_INTERVAL, $connection),
            self::positiveInteger($writeOptions, 'maxBuffered', self::DEFAULT_BUFFERED_BATCHES * $batchSize, $connection),
            $overflow,
            $onFailure,
        );
    }

    /**
     * Read an option that must be a whole number above zero.
     *
     * @param array<string, mixed> $options
     */
    private static function positiveInteger(array $options, string $key, int $default, string $connection): int
    {
        $value = $options[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (is_numeric($value) && (float) $value >= 1 && (float) $value === floor((float) $value)) {
            return (int) $value;
        }

        throw self::invalid($connection, $key, $value, 'a whole number above zero');
    }

    /**
     * Read an option that must be a number above zero.
     *
     * @param array<string, mixed> $options
     */
    private static function positiveNumber(array $options, string $key, float|int $default, string $connection): float
    {
        $value = $options[$key] ?? null;

        if ($value === null) {
            return (float) $default;
        }

        if (is_numeric($value) && (float) $value > 0) {
            return (float) $value;
        }

        throw self::invalid($connection, $key, $value, 'a number above zero');
    }

    /**
     * Describe an option that is out of range or of the wrong type.
     */
    private static function invalid(string $connection, string $key, mixed $value, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'InfluxDB connection [%s] has an invalid write.%s [%s]; expected %s.',
            $connection,
            $key,
            match (true) {
                is_string($value) => $value,
                is_scalar($value) => var_export($value, true),
                default => get_debug_type($value),
            },
            $expected,
        ));
    }
}
