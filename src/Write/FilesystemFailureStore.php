<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Write;

use DateTimeImmutable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory;
use Hypervel\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * The `filesystem` driver: keeps each failed batch as a JSON file on a disk, and replays them oldest first.
 *
 * The file name starts with when the batch failed, so the names sort in failure order.
 * The disk is resolved on first use, not when the connection is configured.
 *
 * @see docs/writing.md#the-filesystem-driver
 */
final class FilesystemFailureStore implements ReplayableFailureStore
{
    public const string DRIVER = 'filesystem';

    private const string FAILED_AT = 'Y-m-d\TH:i:s.uP';

    private ?Filesystem $filesystem = null;

    /**
     * @param string $path the directory the batches are kept in, on the disk
     * @param null|string $disk the disk's name; null is the application's default disk
     */
    public function __construct(
        private readonly string $path,
        private readonly ?string $disk = null,
    ) {
    }

    /**
     * Keep a batch that failed after its retries as a file of its own.
     */
    public function store(FailedBatch $batch): void
    {
        $name = sprintf('%s/%s-%s.json', $this->path, $batch->failedAt->format('YmdHisu'), bin2hex(random_bytes(4)));

        $contents = json_encode([
            'connection' => $batch->connection,
            'bucket' => $batch->bucket,
            'org' => $batch->org,
            'precision' => $batch->precision,
            'payload' => $batch->payload,
            'attempts' => $batch->attempts,
            'failedAt' => $batch->failedAt->format(self::FAILED_AT),
            'exception' => $batch->exception,
        ], JSON_THROW_ON_ERROR);

        if ($this->filesystem()->put($name, $contents) === false) {
            throw new RuntimeException("The failure store could not write [{$name}].");
        }
    }

    /**
     * Hand up to the given number of stored batches, oldest first, to the writer.
     *
     * @throws RuntimeException when a stored file cannot be read as a batch; the file is left in place
     */
    public function replay(callable $writer, int $limit = 100): int
    {
        $filesystem = $this->filesystem();
        $handed = 0;

        foreach (array_slice($filesystem->files($this->path), 0, max(0, $limit)) as $file) {
            $writer($this->read($filesystem, $file));
            $filesystem->delete($file);

            ++$handed;
        }

        return $handed;
    }

    /**
     * Read a stored batch back from its file.
     *
     * @throws RuntimeException when the file is unreadable or is not a batch
     */
    private function read(Filesystem $filesystem, string $file): FailedBatch
    {
        try {
            $data = json_decode((string) $filesystem->get($file), true, flags: JSON_THROW_ON_ERROR);

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
            throw new RuntimeException("The failure store could not read [{$file}] as a failed batch.", previous: $exception);
        }
    }

    private function filesystem(): Filesystem
    {
        return $this->filesystem ??= Container::getInstance()->make(Factory::class)->disk($this->disk);
    }
}
