<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use DateTimeImmutable;
use Throwable;

/**
 * A Batch that could not be written, with what a FailureStore needs to keep it and write it again.
 *
 * @see docs/writing.md#when-a-batch-cannot-be-written
 */
final class FailedBatch
{
    /**
     * @param string $connection the name of the connection that buffered it
     * @param string $bucket the bucket it was going to
     * @param string $org the org it was going to
     * @param string $precision the precision of its timestamps
     * @param string $payload its line protocol, one point per line
     * @param int $attempts the times it was sent through its own connection: the first try and each retry the client made
     * @param DateTimeImmutable $failedAt when its last attempt failed
     * @param string $exception the class and message of the failure
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $bucket,
        public readonly string $org,
        public readonly string $precision,
        public readonly string $payload,
        public readonly int $attempts,
        public readonly DateTimeImmutable $failedAt,
        public readonly string $exception,
    ) {
    }

    /**
     * Describe a batch that failed with the given exception after the given number of attempts.
     */
    public static function from(BatchWriteException $exception, int $attempts): self
    {
        $cause = $exception->getPrevious() ?? $exception;

        return new self(
            $exception->batch->connection,
            $exception->batch->bucket,
            $exception->batch->org,
            $exception->batch->precision,
            $exception->batch->payload,
            $attempts,
            new DateTimeImmutable,
            sprintf('%s: %s', $cause::class, $cause->getMessage()),
        );
    }

    /**
     * Describe this batch after a replay of it failed with the given exception.
     */
    public function replayFailed(Throwable $cause): self
    {
        return new self(
            $this->connection,
            $this->bucket,
            $this->org,
            $this->precision,
            $this->payload,
            $this->attempts + 1,
            new DateTimeImmutable,
            sprintf('%s: %s', $cause::class, $cause->getMessage()),
        );
    }
}
