<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use DateTimeImmutable;
use Hypervel\Contracts\Filesystem\Factory;
use Hypervel\Contracts\Filesystem\Filesystem;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\FailedBatch;
use Ipsocode\InfluxDB\Write\FilesystemFailureStore;
use Mockery;
use RuntimeException;

/**
 * The filesystem driver, on a local disk under a temporary directory.
 */
class FilesystemFailureStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/influxdb-failed-' . bin2hex(random_bytes(4));
        mkdir($this->root);

        $config = $this->app->get('config');
        $config->set('filesystems.disks.failed', ['driver' => 'local', 'root' => $this->root]);
        $config->set('filesystems.default', 'failed');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->root . '/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }

        rmdir($this->root);

        parent::tearDown();
    }

    public function testABatchIsKeptAsAJsonFileWithItsPayloadUnchanged(): void
    {
        $payload = "cpu,host=a load=1i 1\ncpu,host=b note=\"é\\\"x\" 2";

        (new FilesystemFailureStore('spill', 'failed'))->store($this->failed($payload, 3));

        $files = glob($this->root . '/spill/*.json');
        $this->assertCount(1, $files);
        $this->assertSame(
            ['main', 'bucket', 'org', 's', $payload, 3, '2026-10-22T00:00:00.123456+00:00', 'RuntimeException: down'],
            array_values(json_decode((string) file_get_contents($files[0]), true)),
        );
    }

    public function testBatchesAreReplayedOldestFirstAndRemovedOnlyAfterTheWriterReturns(): void
    {
        $store = new FilesystemFailureStore('spill', 'failed');
        $store->store($this->failed('second', 1, '2026-10-22T00:00:02'));
        $store->store($this->failed('first', 1, '2026-10-22T00:00:01'));
        $seen = [];

        $handed = $store->replay(function (FailedBatch $batch) use (&$seen): void {
            $seen[] = $batch->payload;
            $this->assertCount(2 - count($seen) + 1, glob($this->root . '/spill/*.json'));
        });

        $this->assertSame(2, $handed);
        $this->assertSame(['first', 'second'], $seen);
        $this->assertSame([], glob($this->root . '/spill/*.json'));
    }

    public function testAReplayedBatchComesBackAsItWasStored(): void
    {
        $store = new FilesystemFailureStore('spill', 'failed');
        $original = $this->failed('cpu load=1i 1', 4);
        $store->store($original);

        $store->replay(function (FailedBatch $batch) use ($original): void {
            $this->assertEquals($original, $batch);
        });
    }

    public function testTheLimitBoundsTheBatchesReplayed(): void
    {
        $store = new FilesystemFailureStore('spill');
        $store->store($this->failed('a', 1, '2026-10-22T00:00:01'));
        $store->store($this->failed('b', 1, '2026-10-22T00:00:02'));
        $store->store($this->failed('c', 1, '2026-10-22T00:00:03'));
        $seen = [];

        $this->assertSame(2, $store->replay(function (FailedBatch $batch) use (&$seen): void {
            $seen[] = $batch->payload;
        }, 2));

        $this->assertSame(['a', 'b'], $seen);
        $this->assertCount(1, glob($this->root . '/spill/*.json'));
        $this->assertSame(0, $store->replay(static fn () => null, 0));
    }

    public function testAWriterThatThrowsKeepsItsFileAndEndsTheReplay(): void
    {
        $store = new FilesystemFailureStore('spill', 'failed');
        $store->store($this->failed('a', 1, '2026-10-22T00:00:01'));
        $store->store($this->failed('b', 1, '2026-10-22T00:00:02'));

        try {
            $store->replay(static function (): void {
                throw new RuntimeException('InfluxDB is down.');
            });
            $this->fail('The writer\'s exception should end the replay.');
        } catch (RuntimeException $exception) {
            $this->assertSame('InfluxDB is down.', $exception->getMessage());
        }

        $this->assertCount(2, glob($this->root . '/spill/*.json'));
    }

    public function testAnEmptyStoreReplaysNothing(): void
    {
        $this->assertSame(0, (new FilesystemFailureStore('spill'))->replay(static fn () => null));
    }

    public function testAFileThatIsNotABatchEndsTheReplayAndStaysStored(): void
    {
        mkdir($this->root . '/spill');
        file_put_contents($this->root . '/spill/0-bad.json', '{"connection":"main"}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not read [spill/0-bad.json] as a failed batch');

        try {
            (new FilesystemFailureStore('spill'))->replay(static fn () => null);
        } finally {
            $this->assertFileExists($this->root . '/spill/0-bad.json');
        }
    }

    public function testAFileThatIsNotJsonIsRefusedToo(): void
    {
        mkdir($this->root . '/spill');
        file_put_contents($this->root . '/spill/0-bad.json', 'not json');

        $this->expectException(RuntimeException::class);

        (new FilesystemFailureStore('spill'))->replay(static fn () => null);
    }

    public function testADiskThatCannotBeWrittenToIsReported(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('disk')->with('failed')->andReturn($disk);
        $this->app->instance(Factory::class, $factory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not write [spill/');

        (new FilesystemFailureStore('spill', 'failed'))->store($this->failed('a'));
    }

    private function failed(string $payload, int $attempts = 1, string $failedAt = '2026-10-22T00:00:00.123456+00:00'): FailedBatch
    {
        return new FailedBatch('main', 'bucket', 'org', 's', $payload, $attempts, new DateTimeImmutable($failedAt), 'RuntimeException: down');
    }
}
