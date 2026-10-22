<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use DateTimeImmutable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Redis\RedisProxy;
use RuntimeException;
use Throwable;

/**
 * The `redis` driver: keeps each failed batch as a JSON entry of a Redis list, and replays them oldest first.
 *
 * The list is `influxdb:failed:<connection>`, on the named Redis connection, which is resolved on each call.
 *
 * @see docs/writing.md#the-redis-driver
 */
final class RedisFailureStore implements ReplayableFailureStore
{
    public const string DRIVER = 'redis';

    private const string FAILED_AT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param string $key the list the batches are kept in
     * @param null|string $connection the Redis connection's name; null is the application's default connection
     */
    public function __construct(
        private readonly string $key,
        private readonly ?string $connection = null,
    ) {
    }

    /**
     * Keep a batch that failed after its retries at the end of the list.
     */
    public function store(FailedBatch $batch): void
    {
        $entry = json_encode([
            'connection' => $batch->connection,
            'bucket' => $batch->bucket,
            'org' => $batch->org,
            'precision' => $batch->precision,
            'payload' => $batch->payload,
            'attempts' => $batch->attempts,
            'failedAt' => $batch->failedAt->format(self::FAILED_AT),
            'exception' => $batch->exception,
        ], JSON_THROW_ON_ERROR);

        if ($this->redis()->rPush($this->key, $entry) === false) {
            throw new RuntimeException("The failure store could not append to [{$this->key}].");
        }
    }

    /**
     * Hand up to the given number of stored batches, oldest first, to the writer.
     *
     * @throws RuntimeException when a stored entry cannot be read as a batch; the entry is left in place
     */
    public function replay(callable $writer, int $limit = 100): int
    {
        if ($limit < 1) {
            return 0;
        }

        $redis = $this->redis();
        $handed = 0;

        foreach ($redis->lrange($this->key, 0, $limit - 1) as $entry) {
            $writer($this->read($entry));
            $redis->lrem($this->key, 1, $entry);

            ++$handed;
        }

        return $handed;
    }

    /**
     * Read a stored batch back from its entry.
     *
     * @throws RuntimeException when the entry is not a batch
     */
    private function read(string $entry): FailedBatch
    {
        try {
            $data = json_decode($entry, true, flags: JSON_THROW_ON_ERROR);

            return new FailedBatch(
                $data['connection'],
                $data['bucket'],
                $data['org'],
                $data['precision'],
                $data['payload'],
                $data['attempts'],
                new DateTimeImmutable($data['failedAt']),
                $data['exception'],
            );
        } catch (Throwable $exception) {
            throw new RuntimeException("The failure store could not read an entry of [{$this->key}] as a failed batch.", previous: $exception);
        }
    }

    private function redis(): RedisProxy
    {
        return Container::getInstance()->make(Factory::class)->connection($this->connection);
    }
}
