<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

/**
 * A FailureStore that can hand back the batches it kept, to be written again.
 *
 * It is a separate interface, so an application's own FailureStore works unchanged.
 *
 * @see src/docs/writing.md#replaying-failed-batches
 */
interface ReplayableFailureStore extends FailureStore
{
    /**
     * Hand up to the given number of stored batches, oldest first, to the writer.
     *
     * A batch is removed only after the writer returns for it; a writer that throws leaves
     * its batch stored and ends the replay with the exception.
     *
     * @param callable(FailedBatch): void $writer
     *
     * @return int the batches handed to the writer
     */
    public function replay(callable $writer, int $limit = 100): int;
}
