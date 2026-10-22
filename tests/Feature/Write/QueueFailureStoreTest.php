<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Hypervel\Support\Facades\Queue;
use InfluxDB2\WriteType;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Jobs\ReplayFailedBatch;
use Ipsocode\InfluxDB\Tests\Concerns\MocksWrites;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\FailedBatch;
use Ipsocode\InfluxDB\Write\QueueFailureStore;
use Throwable;

/**
 * The queue driver, against a faked queue, and the job it dispatches.
 */
class QueueFailureStoreTest extends TestCase
{
    use MocksWrites;

    public function testABatchIsDispatchedAsAJobOnTheConfiguredConnectionAndQueue(): void
    {
        $queue = Queue::fake();
        $batch = $this->failed('cpu load=1i 1');

        (new QueueFailureStore('redis', 'influxdb'))->store($batch);

        $queue->assertPushed(ReplayFailedBatch::class, static fn (ReplayFailedBatch $job): bool => $job->batch === $batch && $job->connection === 'redis' && $job->queue === 'influxdb');
        $queue->assertPushed(ReplayFailedBatch::class, 1);
    }

    public function testTheDefaultConnectionAndQueueAreLeftToTheQueue(): void
    {
        $queue = Queue::fake();

        (new QueueFailureStore)->store($this->failed('cpu load=1i 1'));

        $queue->assertPushed(ReplayFailedBatch::class, static fn (ReplayFailedBatch $job): bool => $job->connection === null && $job->queue === null);
    }

    public function testTheJobWritesTheBatchToWhereItWasGoing(): void
    {
        $this->connection([new Response(204)]);

        (new ReplayFailedBatch($this->failed('cpu load=1i 1', 'ms')))->handle($this->app->get(InfluxDBManager::class));

        $this->assertSame([['cpu load=1i 1']], $this->sentBatches());
    }

    public function testTheJobThrowsWhenTheWriteFailsSoTheQueueRetriesIt(): void
    {
        $this->connection([new Response(400, [], '{"code":"invalid","message":"unable to parse"}')]);

        $thrown = null;

        try {
            (new ReplayFailedBatch($this->failed('cpu load=1i 1')))->handle($this->app->get(InfluxDBManager::class));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown);
    }

    private function failed(string $payload, string $precision = 'ns'): FailedBatch
    {
        return new FailedBatch('main', 'main-bucket', 'main-org', $precision, $payload, 1, new DateTimeImmutable, 'RuntimeException: down');
    }

    /**
     * Make the `main` connection answer with the given responses.
     *
     * @param list<Response> $responses
     */
    private function connection(array $responses): void
    {
        $config = $this->app->get('config');

        $config->set('influxdb.connections.main.write', ['writeType' => WriteType::BATCHING, 'maxRetries' => 0]);
        $config->set('influxdb.connections.main.logFile', '/dev/null');
        $config->set('influxdb.connections.main.httpClient', $this->transport($responses));
    }
}
