<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use InfluxDB2\WriteType;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * When a BatchingWriter sends a batch, and what it does when it cannot.
 *
 * A megabyte of `batchSizeMb` is 1,000,000 bytes, so a size limit stays under
 * a server's limit whichever way the server counts it.
 *
 * @see docs/writing.md#when-a-batch-is-sent
 */
final class BatchOptions
{
    /**
     * Past `maxBuffered`, write() sends the buffer itself before it returns.
     */
    public const string FLUSH = 'flush';

    /**
     * Past `maxBuffered`, write() throws a BufferFullException and keeps none of the points it refuses.
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
     * The retries a failed batch gets when `maxRetries` is not set.
     */
    public const int DEFAULT_MAX_RETRIES = 3;

    /**
     * The times a failed batch is sent, replays included, before it is dropped when `maxAttempts` is not set.
     */
    public const int DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * @param int $batchSize the lines of line protocol a batch is sent at
     * @param int $batchBytes the bytes a batch is sent at, and no other write makes it outgrow
     * @param float $flushInterval the seconds after its first point a batch is sent at the latest
     * @param int $maxBuffered the points held at most, buffered or being sent, before `overflow` applies
     * @param string $overflow what write() does past `maxBuffered`: FLUSH or REFUSE
     * @param null|callable|class-string|FailureStore $onFailure called with the Batch and the BatchWriteException of each batch that could not be written, or the store handed its FailedBatch; null logs it
     * @param int $maxAttempts the times a batch a store kept is sent, replays included, before a replay drops it
     */
    public function __construct(
        public readonly int $batchSize,
        public readonly int $batchBytes,
        public readonly float $flushInterval,
        public readonly int $maxBuffered,
        public readonly string $overflow = self::FLUSH,
        public readonly mixed $onFailure = null,
        public readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
    ) {
    }

    /**
     * Determine whether a connection's write options ask for batching.
     *
     * `writeType` is the switch: WriteType::BATCHING turns batching on, and
     * anything else, or nothing, leaves writes synchronous.
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
        // InfluxData's recommended limits for each version.
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

        if (in_array($onFailure, [LogFailureStore::DRIVER, FilesystemFailureStore::DRIVER, RedisFailureStore::DRIVER, DatabaseFailureStore::DRIVER, QueueFailureStore::DRIVER], true) || (is_array($onFailure) && array_key_exists('driver', $onFailure))) {
            $onFailure = self::store($onFailure, $connection);
        }

        if ($onFailure !== null && ! $onFailure instanceof FailureStore && ! is_callable($onFailure) && ! (is_string($onFailure) && method_exists($onFailure, '__invoke'))) {
            throw self::invalid($connection, 'onFailure', $onFailure, 'a callable or the name of an invokable class');
        }

        return new self(
            $batchSize,
            max(1, (int) round($megabytes * 1_000_000)),
            self::positiveNumber($writeOptions, 'flushInterval', self::DEFAULT_FLUSH_INTERVAL, $connection),
            self::positiveInteger($writeOptions, 'maxBuffered', self::DEFAULT_BUFFERED_BATCHES * $batchSize, $connection),
            $overflow,
            $onFailure,
            self::positiveInteger($writeOptions, 'maxAttempts', self::DEFAULT_MAX_ATTEMPTS, $connection),
        );
    }

    /**
     * Resolve a failure store from its driver name or driver array.
     *
     * @param array<array-key, mixed>|string $driver
     *
     * @throws InvalidArgumentException when the driver is unknown, or an option of it is of the wrong kind
     */
    private static function store(array|string $driver, string $connection): FailureStore
    {
        $name = is_array($driver) ? $driver['driver'] : $driver;

        if ($name === LogFailureStore::DRIVER) {
            return new LogFailureStore;
        }

        if ($name === FilesystemFailureStore::DRIVER) {
            $options = is_array($driver) ? $driver : [];

            return new FilesystemFailureStore(
                self::storeOption($options, 'path', "influxdb/failed/{$connection}", $connection),
                self::storeOption($options, 'disk', null, $connection),
            );
        }

        if ($name === RedisFailureStore::DRIVER) {
            $options = is_array($driver) ? $driver : [];

            return new RedisFailureStore(
                "influxdb:failed:{$connection}",
                self::storeOption($options, 'connection', null, $connection),
            );
        }

        if ($name === DatabaseFailureStore::DRIVER) {
            $options = is_array($driver) ? $driver : [];

            return new DatabaseFailureStore(
                $connection,
                self::storeOption($options, 'table', DatabaseFailureStore::DEFAULT_TABLE, $connection),
                self::storeOption($options, 'connection', null, $connection),
            );
        }

        if ($name === QueueFailureStore::DRIVER) {
            $options = is_array($driver) ? $driver : [];

            return new QueueFailureStore(
                self::storeOption($options, 'connection', null, $connection),
                self::storeOption($options, 'queue', null, $connection),
            );
        }

        throw self::invalid($connection, 'onFailure', $name, sprintf('a callable, the name of an invokable class, or the driver %s, %s, %s, %s or %s', LogFailureStore::DRIVER, FilesystemFailureStore::DRIVER, RedisFailureStore::DRIVER, DatabaseFailureStore::DRIVER, QueueFailureStore::DRIVER));
    }

    /**
     * Read an option of a failure store driver that must be a non-empty string.
     *
     * @param array<array-key, mixed> $options
     */
    private static function storeOption(array $options, string $key, ?string $default, string $connection): ?string
    {
        $value = $options[$key] ?? $default;

        if ($value === $default || (is_string($value) && $value !== '')) {
            return $value;
        }

        throw self::invalid($connection, "onFailure.{$key}", $value, 'a non-empty string');
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
