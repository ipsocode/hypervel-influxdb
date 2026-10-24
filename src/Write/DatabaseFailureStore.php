<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use DateTimeImmutable;
use DateTimeZone;
use Hypervel\Container\Container;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * The `database` driver: keeps each failed batch as a row of a table, and replays them oldest first.
 *
 * The table is `influxdb_failed_batches` unless named otherwise, on the named database connection, which
 * is resolved on each call. Its rows are told apart by the InfluxDB connection that buffered them.
 *
 * @see src/docs/writing.md#the-database-driver
 */
final class DatabaseFailureStore implements ReplayableFailureStore
{
    public const string DRIVER = 'database';

    public const string DEFAULT_TABLE = 'influxdb_failed_batches';

    private const string FAILED_AT = 'Y-m-d H:i:s.u';

    /**
     * @param string $influxConnection the name of the InfluxDB connection whose batches the store keeps
     * @param string $table the table the batches are kept in
     * @param null|string $connection the database connection's name; null is the application's default connection
     */
    public function __construct(
        private readonly string $influxConnection,
        private readonly string $table = self::DEFAULT_TABLE,
        private readonly ?string $connection = null,
    ) {
    }

    /**
     * Keep a batch that failed after its retries as a new row.
     */
    public function store(FailedBatch $batch): void
    {
        $this->database()->table($this->table)->insert([
            'connection' => $this->influxConnection,
            'bucket' => $batch->bucket,
            'org' => $batch->org,
            'precision' => $batch->precision,
            'payload' => $batch->payload,
            'attempts' => $batch->attempts,
            'failed_at' => $batch->failedAt->setTimezone(new DateTimeZone('UTC'))->format(self::FAILED_AT),
            'exception' => $batch->exception,
        ]);
    }

    /**
     * Hand up to the given number of stored batches, oldest first, to the writer.
     *
     * @throws RuntimeException when a stored row cannot be read as a batch; the row is left in place
     */
    public function replay(callable $writer, int $limit = 100): int
    {
        if ($limit < 1) {
            return 0;
        }

        $database = $this->database();
        $handed = 0;

        $rows = $database->table($this->table)
            ->where('connection', $this->influxConnection)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $writer($this->read($row));
            $database->table($this->table)->where('id', $row->id)->delete();

            ++$handed;
        }

        return $handed;
    }

    /**
     * Read a stored batch back from its row.
     *
     * @throws RuntimeException when the row is not a batch
     */
    private function read(object $row): FailedBatch
    {
        try {
            return new FailedBatch(
                $row->connection,
                $row->bucket,
                $row->org,
                $row->precision,
                $row->payload,
                (int) $row->attempts,
                new DateTimeImmutable($row->failed_at, new DateTimeZone('UTC')),
                $row->exception,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException("The failure store could not read row [{$row->id}] of [{$this->table}] as a failed batch.", previous: $exception);
        }
    }

    private function database(): ConnectionInterface
    {
        return Container::getInstance()->make(DatabaseManager::class)->connection($this->connection);
    }
}
