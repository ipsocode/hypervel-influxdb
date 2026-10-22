<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use DateTimeImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\DatabaseFailureStore;
use Ipsocode\InfluxDB\Write\FailedBatch;
use RuntimeException;

/**
 * The database driver, on SQLite `:memory:` with the package's migration.
 */
class DatabaseFailureStoreTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 3) . '/database/migrations');
    }

    public function testABatchIsKeptAsARowWithItsPayloadUnchanged(): void
    {
        $payload = "cpu,host=a load=1i 1\ncpu,host=b note=\"é\\\"x\" 2";

        (new DatabaseFailureStore('main'))->store($this->failed($payload, 3));

        $rows = DB::table('influxdb_failed_batches')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(
            ['main', 'bucket', 'org', 's', $payload, 3, '2026-10-22 00:00:00.123456', 'RuntimeException: down'],
            [$rows[0]->connection, $rows[0]->bucket, $rows[0]->org, $rows[0]->precision, $rows[0]->payload, (int) $rows[0]->attempts, $rows[0]->failed_at, $rows[0]->exception],
        );
    }

    public function testBatchesAreReplayedOldestFirstAndRemovedOnlyAfterTheWriterReturns(): void
    {
        $store = new DatabaseFailureStore('main');
        $store->store($this->failed('first'));
        $store->store($this->failed('second'));
        $seen = [];

        $handed = $store->replay(function (FailedBatch $batch) use (&$seen): void {
            $seen[] = $batch->payload;
            $this->assertSame(2 - count($seen) + 1, DB::table('influxdb_failed_batches')->count());
        });

        $this->assertSame(2, $handed);
        $this->assertSame(['first', 'second'], $seen);
        $this->assertSame(0, DB::table('influxdb_failed_batches')->count());
    }

    public function testAReplayedBatchComesBackAsItWasStored(): void
    {
        $store = new DatabaseFailureStore('main');
        $original = $this->failed('cpu load=1i 1', 4, '2026-10-22T02:00:00.123456+02:00');
        $store->store($original);

        $store->replay(function (FailedBatch $batch) use ($original): void {
            $this->assertEquals($original, $batch);
        });
    }

    public function testTheLimitBoundsTheBatchesReplayed(): void
    {
        $store = new DatabaseFailureStore('main');
        $store->store($this->failed('a'));
        $store->store($this->failed('b'));
        $store->store($this->failed('c'));
        $seen = [];

        $this->assertSame(2, $store->replay(function (FailedBatch $batch) use (&$seen): void {
            $seen[] = $batch->payload;
        }, 2));

        $this->assertSame(['a', 'b'], $seen);
        $this->assertSame(1, DB::table('influxdb_failed_batches')->count());
        $this->assertSame(0, $store->replay(static fn () => null, 0));
        $this->assertSame(1, DB::table('influxdb_failed_batches')->count());
    }

    public function testAWriterThatThrowsKeepsItsRowAndEndsTheReplay(): void
    {
        $store = new DatabaseFailureStore('main');
        $store->store($this->failed('a'));
        $store->store($this->failed('b'));

        try {
            $store->replay(static function (): void {
                throw new RuntimeException('InfluxDB is down.');
            });
            $this->fail('The writer\'s exception should end the replay.');
        } catch (RuntimeException $exception) {
            $this->assertSame('InfluxDB is down.', $exception->getMessage());
        }

        $this->assertSame(2, DB::table('influxdb_failed_batches')->count());
    }

    public function testAConnectionReplaysOnlyItsOwnBatches(): void
    {
        (new DatabaseFailureStore('other'))->store($this->failed('theirs'));
        $store = new DatabaseFailureStore('main');
        $store->store($this->failed('mine'));
        $seen = [];

        $this->assertSame(1, $store->replay(function (FailedBatch $batch) use (&$seen): void {
            $seen[] = $batch->payload;
        }));

        $this->assertSame(['mine'], $seen);
        $this->assertSame(1, DB::table('influxdb_failed_batches')->where('connection', 'other')->count());
    }

    public function testAnEmptyStoreReplaysNothing(): void
    {
        $this->assertSame(0, (new DatabaseFailureStore('main'))->replay(static fn () => null));
    }

    public function testATableAndAConnectionCanBeNamed(): void
    {
        Schema::create('spill', function ($table): void {
            $table->id();
            $table->string('connection');
            $table->string('bucket');
            $table->string('org');
            $table->string('precision', 2);
            $table->longText('payload');
            $table->unsignedInteger('attempts');
            $table->dateTime('failed_at', 6);
            $table->text('exception');
        });
        $store = new DatabaseFailureStore('main', 'spill', 'testing');
        $store->store($this->failed('a'));

        $this->assertSame(1, DB::table('spill')->count());
        $this->assertSame(1, $store->replay(static fn () => null));
    }

    public function testARowThatIsNotABatchEndsTheReplayAndStaysStored(): void
    {
        (new DatabaseFailureStore('main'))->store($this->failed('a'));
        DB::table('influxdb_failed_batches')->update(['failed_at' => 'not a date']);

        try {
            (new DatabaseFailureStore('main'))->replay(static fn () => null);
            $this->fail('A row that is not a batch should end the replay.');
        } catch (RuntimeException $exception) {
            $this->assertMatchesRegularExpression('/^The failure store could not read row \[\d+\] of \[influxdb_failed_batches\] as a failed batch\.$/', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('influxdb_failed_batches')->count());
    }

    private function failed(string $payload, int $attempts = 1, string $failedAt = '2026-10-22T00:00:00.123456+00:00'): FailedBatch
    {
        return new FailedBatch('main', 'bucket', 'org', 's', $payload, $attempts, new DateTimeImmutable($failedAt), 'RuntimeException: down');
    }
}
