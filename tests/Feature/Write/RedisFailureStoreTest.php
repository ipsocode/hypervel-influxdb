<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use DateTimeImmutable;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Redis\RedisProxy;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\FailedBatch;
use Ipsocode\InfluxDB\Write\RedisFailureStore;
use Mockery as m;
use RuntimeException;

/**
 * The redis driver, against a Redis connection that keeps its lists in an array.
 */
class RedisFailureStoreTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $lists = [];

    private ?bool $pushFails = null;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('rPush')->andReturnUsing(function (string $key, string $entry) {
            if ($this->pushFails) {
                return false;
            }

            $this->lists[$key][] = $entry;

            return count($this->lists[$key]);
        });
        $redis->shouldReceive('lrange')->andReturnUsing(fn (string $key, int $start, int $stop) => array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1));
        $redis->shouldReceive('lrem')->andReturnUsing(function (string $key, int $count, string $entry) {
            $index = array_search($entry, $this->lists[$key], true);
            unset($this->lists[$key][$index]);
            $this->lists[$key] = array_values($this->lists[$key]);

            return 1;
        });

        $factory = m::mock(Factory::class);
        $factory->shouldReceive('connection')->with('cache')->andReturn($redis);
        $this->app->instance(Factory::class, $factory);
    }

    public function testABatchIsAppendedAsJsonWithItsPayloadUnchanged(): void
    {
        $payload = "cpu,host=a load=1i 1\ncpu,host=b note=\"é\\\"x\" 2";

        (new RedisFailureStore('influxdb:failed:main', 'cache'))->store($this->failed($payload, 3));

        $this->assertCount(1, $this->lists['influxdb:failed:main']);
        $this->assertSame(
            ['main', 'bucket', 'org', 's', $payload, 3, '2026-10-22T00:00:00.123456+00:00', 'RuntimeException: down'],
            array_values(json_decode($this->lists['influxdb:failed:main'][0], true)),
        );
    }

    public function testBatchesAreReplayedOldestFirstAndRemovedOnlyAfterTheWriterReturns(): void
    {
        $store = new RedisFailureStore('influxdb:failed:main', 'cache');
        $store->store($this->failed('first'));
        $store->store($this->failed('second'));
        $seen = [];

        $handed = $store->replay(function (FailedBatch $batch) use (&$seen): void {
            $seen[] = $batch->payload;
            $this->assertCount(2 - count($seen) + 1, $this->lists['influxdb:failed:main']);
        });

        $this->assertSame(2, $handed);
        $this->assertSame(['first', 'second'], $seen);
        $this->assertSame([], $this->lists['influxdb:failed:main']);
    }

    public function testAReplayedBatchComesBackAsItWasStored(): void
    {
        $store = new RedisFailureStore('influxdb:failed:main', 'cache');
        $original = $this->failed('cpu load=1i 1', 4);
        $store->store($original);

        $store->replay(function (FailedBatch $batch) use ($original): void {
            $this->assertEquals($original, $batch);
        });
    }

    public function testTheLimitBoundsTheBatchesReplayed(): void
    {
        $store = new RedisFailureStore('influxdb:failed:main', 'cache');
        $store->store($this->failed('a'));
        $store->store($this->failed('b'));
        $store->store($this->failed('c'));
        $seen = [];

        $this->assertSame(2, $store->replay(function (FailedBatch $batch) use (&$seen): void {
            $seen[] = $batch->payload;
        }, 2));

        $this->assertSame(['a', 'b'], $seen);
        $this->assertCount(1, $this->lists['influxdb:failed:main']);
        $this->assertSame(0, $store->replay(static fn () => null, 0));
        $this->assertCount(1, $this->lists['influxdb:failed:main']);
    }

    public function testAWriterThatThrowsKeepsItsEntryAndEndsTheReplay(): void
    {
        $store = new RedisFailureStore('influxdb:failed:main', 'cache');
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

        $this->assertCount(2, $this->lists['influxdb:failed:main']);
    }

    public function testAnEmptyStoreReplaysNothing(): void
    {
        $this->assertSame(0, (new RedisFailureStore('influxdb:failed:main', 'cache'))->replay(static fn () => null));
    }

    public function testAnEntryThatIsNotABatchEndsTheReplayAndStaysStored(): void
    {
        $this->lists['influxdb:failed:main'] = ['{"connection":"main"}'];

        try {
            (new RedisFailureStore('influxdb:failed:main', 'cache'))->replay(static fn () => null);
            $this->fail('An entry that is not a batch should end the replay.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The failure store could not read an entry of [influxdb:failed:main] as a failed batch.', $exception->getMessage());
        }

        $this->assertCount(1, $this->lists['influxdb:failed:main']);
    }

    public function testAListThatCannotBeAppendedToIsReported(): void
    {
        $this->pushFails = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not append to [influxdb:failed:main]');

        (new RedisFailureStore('influxdb:failed:main', 'cache'))->store($this->failed('a'));
    }

    private function failed(string $payload, int $attempts = 1): FailedBatch
    {
        return new FailedBatch('main', 'bucket', 'org', 's', $payload, $attempts, new DateTimeImmutable('2026-10-22T00:00:00.123456+00:00'), 'RuntimeException: down');
    }
}
