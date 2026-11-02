<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use InfluxDB2\WriteType;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Facades\InfluxDB;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Tests\Concerns\MocksWrites;
use Ipsocode\InfluxDB\Tests\Fixtures\InMemoryFailureStore;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\FailedBatch;
use Mockery as m;
use Psr\Log\LoggerInterface;

/**
 * Replaying the batches a ReplayableFailureStore kept, through the manager and the command.
 */
class ReplayFailedTest extends TestCase
{
    use MocksWrites;

    public function testAReplayedBatchIsWrittenToWhereItWasGoingAndRemoved(): void
    {
        $store = $this->store([$this->failed('cpu load=1i 1', 1, 'other-bucket', 'other-org', 's')]);
        $this->connection($store, self::accepted());

        $this->assertSame(1, $this->app->get(InfluxDBManager::class)->replayFailed('main'));

        $this->assertSame([], $store->batches);
        $this->assertSame(['/api/v2/write?org=other-org&bucket=other-bucket&precision=s'], $this->sentTo());
        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
    }

    public function testTheDefaultConnectionIsReplayedWhenNoNameIsGiven(): void
    {
        $store = $this->store([$this->failed('cpu load=1i 1')]);
        $this->connection($store, self::accepted());

        $this->assertSame(1, InfluxDB::replayFailed());
    }

    public function testABatchThatFailsAgainIsStoredAgainWithOneMoreAttempt(): void
    {
        $store = $this->store([$this->failed('cpu load=1i 1', 2)]);
        $this->connection($store, [new Response(400, [], '{"code":"invalid","message":"unable to parse"}')]);

        $this->assertSame(0, $this->app->get(InfluxDBManager::class)->replayFailed('main'));

        $this->assertCount(1, $store->batches);
        $this->assertSame(['cpu load=1i 1', 3], [$store->batches[0]->payload, $store->batches[0]->attempts]);
        $this->assertStringContainsString('unable to parse', $store->batches[0]->exception);
    }

    public function testAReplayIsSentOnceWhateverTheConnectionsMaxRetries(): void
    {
        $store = $this->store([$this->failed('cpu load=1i 1', 2)]);
        $this->connection($store, [new Response(503), new Response(503)], ['maxRetries' => 3]);

        $this->assertSame(0, $this->app->get(InfluxDBManager::class)->replayFailed('main'));

        $this->assertCount(1, $this->sentTo());
        $this->assertCount(1, $store->batches);
        $this->assertSame(3, $store->batches[0]->attempts);
    }

    public function testAReplayEndsAtTheFirstBatchThatFailsAgain(): void
    {
        $store = $this->store([$this->failed('cpu a=1i 1', 1), $this->failed('cpu b=1i 2', 1)]);
        $this->connection($store, [new Response(503)]);

        $this->assertSame(0, $this->app->get(InfluxDBManager::class)->replayFailed('main'));

        $this->assertCount(1, $this->sentTo());
        $this->assertSame(['cpu b=1i 2', 1], [$store->batches[0]->payload, $store->batches[0]->attempts]);
        $this->assertSame(['cpu a=1i 1', 2], [$store->batches[1]->payload, $store->batches[1]->attempts]);
    }

    public function testBatchesAfterTheOneThatFailedAgainAreLeftUntouched(): void
    {
        $store = $this->store([$this->failed('cpu a=1i 1'), $this->failed('cpu b=1i 2'), $this->failed('cpu c=1i 3')]);
        $this->connection($store, [new Response(204), new Response(503)]);

        $this->assertSame(1, $this->app->get(InfluxDBManager::class)->replayFailed('main'));

        $this->assertCount(2, $this->sentTo());
        $this->assertSame(['cpu c=1i 3', 1], [$store->batches[0]->payload, $store->batches[0]->attempts]);
    }

    public function testTheCommandPrintsTheCountForAStoppedReplayAndSucceeds(): void
    {
        $this->connection($this->store([$this->failed('cpu a=1i 1'), $this->failed('cpu b=1i 2')]), [new Response(503)]);

        $this->artisan('influxdb:replay-failed', ['connection' => 'main'])
            ->expectsOutputToContain('Replayed 0 batches on InfluxDB connection [main].')
            ->assertExitCode(0);
    }

    public function testABatchThatReachesMaxAttemptsIsLoggedAndDropped(): void
    {
        $store = $this->store([$this->failed('cpu load=1i 1', 1)]);
        $this->connection($store, [new Response(400, [], 'refused')], ['maxAttempts' => 2]);

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'dropped a batch for bucket [main-bucket] after 2 attempts: '));
        $this->app->instance(LoggerInterface::class, $logger);

        $this->assertSame(0, $this->app->get(InfluxDBManager::class)->replayFailed('main'));

        $this->assertSame([], $store->batches);
    }

    public function testTheLimitIsHonoured(): void
    {
        $store = $this->store([$this->failed('cpu a=1i 1'), $this->failed('cpu b=1i 2'), $this->failed('cpu c=1i 3')]);
        $this->connection($store, self::accepted(2));

        $this->assertSame(2, $this->app->get(InfluxDBManager::class)->replayFailed('main', 2));

        $this->assertSame([['cpu a=1i 1'], ['cpu b=1i 2']], $this->sentBatches());
        $this->assertSame(['cpu c=1i 3'], array_map(static fn (FailedBatch $batch): string => $batch->payload, $store->batches));
    }

    public function testAConnectionWhoseStoreCannotReplayRefusesToReplay(): void
    {
        $this->connection(null, []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [main] has no failure store that can replay its batches');

        $this->app->get(InfluxDBManager::class)->replayFailed('main');
    }

    public function testAConnectionWhoseStoreIsTheQueueLeavesTheReplayingToTheQueue(): void
    {
        $this->connection(null, [], ['onFailure' => 'queue']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [main] has no failure store that can replay its batches: its queue driver leaves the replaying to the queue\'s workers.');

        $this->app->get(InfluxDBManager::class)->replayFailed('main');
    }

    public function testAConnectionThatDoesNotBatchRefusesToReplay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [analytics] has no failure store that can replay its batches');

        $this->app->get(InfluxDBManager::class)->replayFailed('analytics');
    }

    public function testDisconnectingForgetsTheReplayWriter(): void
    {
        $store = $this->store([$this->failed('cpu a=1i 1')]);
        $this->connection($store, self::accepted(2));
        $manager = $this->app->get(InfluxDBManager::class);

        $manager->replayFailed('main');
        $manager->disconnect('main');
        $store->batches[] = $this->failed('cpu b=1i 2');
        $this->connection($store, self::accepted());

        $this->assertSame(1, $manager->replayFailed('main'));
    }

    public function testTheCommandPrintsHowManyBatchesItReplayed(): void
    {
        $this->connection($this->store([$this->failed('cpu a=1i 1'), $this->failed('cpu b=1i 2')]), self::accepted(2));

        $this->artisan('influxdb:replay-failed', ['connection' => 'main'])
            ->expectsOutputToContain('Replayed 2 batches on InfluxDB connection [main].')
            ->assertExitCode(0);
    }

    public function testTheCommandReplaysTheDefaultConnectionAndCountsOneBatch(): void
    {
        $this->connection($this->store([$this->failed('cpu a=1i 1'), $this->failed('cpu b=1i 2')]), self::accepted());

        $this->artisan('influxdb:replay-failed', ['--limit' => '1'])
            ->expectsOutputToContain('Replayed 1 batch on InfluxDB connection [main].')
            ->assertExitCode(0);
    }

    public function testTheCommandFailsForAStoreThatCannotReplay(): void
    {
        $this->connection(null, []);

        $this->artisan('influxdb:replay-failed', ['connection' => 'main'])
            ->expectsOutputToContain('has no failure store that can replay its batches')
            ->assertExitCode(1);
    }

    public function testTheCommandReportsAStoreThatThrows(): void
    {
        $store = $this->store([]);
        $store->broken = true;
        $this->connection($store, []);

        $this->artisan('influxdb:replay-failed', ['connection' => 'main'])
            ->expectsOutputToContain('InfluxDB connection [main] could not replay its failed batches: The store is down.')
            ->assertExitCode(1);
    }

    public function testTheCommandRefusesALimitBelowOne(): void
    {
        $this->artisan('influxdb:replay-failed', ['--limit' => '0'])
            ->expectsOutputToContain('The --limit must be a whole number above zero.')
            ->assertExitCode(1);
    }

    /**
     * @param list<FailedBatch> $batches
     */
    private function store(array $batches): InMemoryFailureStore
    {
        return new InMemoryFailureStore($batches);
    }

    private function failed(string $payload, int $attempts = 1, string $bucket = 'main-bucket', string $org = 'main-org', string $precision = 'ns'): FailedBatch
    {
        return new FailedBatch('main', $bucket, $org, $precision, $payload, $attempts, new DateTimeImmutable, 'RuntimeException: down');
    }

    /**
     * Make the `main` connection batch, with the given store as its `onFailure`.
     *
     * @param list<Response> $responses
     * @param array<string, mixed> $write
     */
    private function connection(?InMemoryFailureStore $store, array $responses, array $write = []): void
    {
        $config = $this->app->get('config');

        $config->set('influxdb.connections.main.write', $write + ['writeType' => WriteType::BATCHING, 'maxRetries' => 0, 'onFailure' => $store]);
        $config->set('influxdb.connections.main.logFile', '/dev/null');
        $config->set('influxdb.connections.main.httpClient', $this->transport($responses));
    }
}
