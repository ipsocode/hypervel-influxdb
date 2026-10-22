<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Client\Common\PluginClient;
use Http\Client\Exception\NetworkException;
use InfluxDB2\ApiException;
use InfluxDB2\Client;
use Ipsocode\InfluxDB\InfluxDBFactory;
use Ipsocode\InfluxDB\Support\RetryableNetworkErrors;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\BatchOptions;
use ReflectionProperty;
use RuntimeException;

/**
 * A refused connection, as Guzzle reports it, is retried by the client's writes.
 *
 * Nothing listens on port 1, so the connection is refused at once, as while InfluxDB restarts.
 */
class NetworkRetryTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = tempnam(sys_get_temp_dir(), 'influxdb-retry');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);

        parent::tearDown();
    }

    public function testASynchronousWriteIsRetriedAfterARefusedConnection(): void
    {
        $writeApi = $this->client()->createWriteApi(['writeType' => 2, 'maxRetries' => 2, 'retryInterval' => 1, 'maxRetryDelay' => 1]);

        try {
            $writeApi->writeRaw('cpu load=1i 1');
            $this->fail('The write should fail once its retries are spent.');
        } catch (ApiException $e) {
            $this->assertInstanceOf(NetworkException::class, $e->getPrevious());
        }

        $this->assertRetried();
    }

    public function testABatchIsRetriedAfterARefusedConnection(): void
    {
        $writer = new BatchingWriter(
            $this->client()->options,
            'main',
            new BatchOptions(batchSize: 100, batchBytes: 1_000_000, flushInterval: 60.0, maxBuffered: 1_000),
            ['maxRetries' => 2, 'retryInterval' => 1, 'maxRetryDelay' => 1],
        );

        $writer->write('cpu load=1i 1');
        $writer->flush();

        $this->assertRetried();
    }

    public function testTheGuzzleClientTheFactoryBuildsGetsTheConnectionsTransportOptions(): void
    {
        $options = (new InfluxDBFactory)->make($this->config(['timeout' => 3, 'proxy' => 'http://proxy.localhost:8080', 'verifySSL' => false]))->options;

        $this->assertInstanceOf(PluginClient::class, $options['httpClient']);

        $emulated = (new ReflectionProperty(PluginClient::class, 'client'))->getValue($options['httpClient']);
        $guzzle = (new ReflectionProperty($emulated, 'httpClient'))->getValue($emulated);
        $config = (new ReflectionProperty(Guzzle::class, 'config'))->getValue($guzzle);

        $this->assertSame(3, $config['timeout']);
        $this->assertFalse($config['verify']);
        $this->assertSame('http://proxy.localhost:8080', $config['proxy']);
    }

    public function testAConnectionsOwnHttpClientIsUsedAsGiven(): void
    {
        $own = new Guzzle;

        $options = (new InfluxDBFactory)->make($this->config(['httpClient' => $own]))->options;

        $this->assertSame($own, $options['httpClient']);
    }

    public function testAnotherFailureReachesTheCallerUnchanged(): void
    {
        $failure = new RuntimeException('boom');
        $client = new PluginClient(
            new Guzzle(['handler' => HandlerStack::create(new MockHandler([$failure]))]),
            [new RetryableNetworkErrors],
        );

        try {
            $client->sendRequest(new Request('GET', 'http://localhost'));
            $this->fail('The request should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame($failure, $e);
        }
    }

    public function testAConnectExceptionBecomesANetworkExceptionWithTheRequestAndThePreviousFailure(): void
    {
        $request = new Request('GET', 'http://localhost');
        $failure = new ConnectException('refused', $request);
        $client = new PluginClient(
            new Guzzle(['handler' => HandlerStack::create(new MockHandler([$failure, new Response(204)]))]),
            [new RetryableNetworkErrors],
        );

        try {
            $client->sendRequest($request);
            $this->fail('The request should fail.');
        } catch (NetworkException $e) {
            $this->assertSame('refused', $e->getMessage());
            $this->assertSame($failure, $e->getPrevious());
            $this->assertSame($request, $e->getRequest());
        }

        $this->assertSame(204, $client->sendRequest($request)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function config(array $options = []): array
    {
        return $options + [
            'url' => 'http://127.0.0.1:1',
            'token' => 'main-token',
            'bucket' => 'main-bucket',
            'org' => 'main-org',
            'logFile' => $this->log,
        ];
    }

    private function client(): Client
    {
        return (new InfluxDBFactory)->make($this->config());
    }

    private function assertRetried(): void
    {
        $log = (string) file_get_contents($this->log);

        $this->assertSame(2, substr_count($log, 'The retryable error occurred'));
        $this->assertStringContainsString('Maximum retry attempts reached', $log);
    }
}
