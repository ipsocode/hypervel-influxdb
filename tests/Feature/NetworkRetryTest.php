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
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Pool\ConnectionLease;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Guzzle\ReleaseIdleDatabaseConnections;
use Hypervel\Testing\ParallelTesting;
use InfluxDB2\ApiException;
use InfluxDB2\Client;
use Ipsocode\InfluxDB\InfluxDBFactory;
use Ipsocode\InfluxDB\Support\RetryableNetworkErrors;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use Ipsocode\InfluxDB\Write\BatchOptions;
use Mockery as m;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * A refused connection, as Guzzle reports it, is retried by the client's writes.
 *
 * The retries go through the factory's plugins over a Guzzle handler that refuses every
 * request, as a server does while InfluxDB restarts, so no socket is opened.
 */
class NetworkRetryTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = ParallelTesting::tempDir('influxdb-retry');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, recursive: true);

        $this->log = $directory . '/client.log';
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(dirname($this->log));

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

    public function testTheClientTheFactoryBuildsReleasesIdleDatabaseSessionsBeforeEachRequest(): void
    {
        $lease = m::mock(ConnectionLease::class);
        $lease->shouldReceive('releaseIfIdle')->twice();
        CoroutineContext::set('__influxdb.test.lease', $lease);

        $httpClient = (new InfluxDBFactory)->make($this->config())->options['httpClient'];

        $plugins = (new ReflectionProperty(PluginClient::class, 'plugins'))->getValue($httpClient);

        $this->assertCount(1, $plugins);
        $this->assertInstanceOf(RetryableNetworkErrors::class, $plugins[0]);

        // The factory's own client and handler stack, with only its transport answering here instead of over the network.
        $emulated = (new ReflectionProperty(PluginClient::class, 'client'))->getValue($httpClient);
        $guzzle = (new ReflectionProperty($emulated, 'httpClient'))->getValue($emulated);
        $stack = (new ReflectionProperty(Guzzle::class, 'config'))->getValue($guzzle)['handler'];

        $stack->setHandler(new MockHandler([new Response(204), new Response(204)]));

        $httpClient->sendRequest(new Request('GET', 'http://localhost'));
        $httpClient->sendRequest(new Request('GET', 'http://localhost'));
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
            'url' => 'http://influxdb.test:8086',
            'token' => 'main-token',
            'bucket' => 'main-bucket',
            'org' => 'main-org',
            'logFile' => $this->log,
        ];
    }

    /**
     * A client whose requests go through the factory's plugins to a server that refuses every connection.
     */
    private function client(): Client
    {
        $stack = HandlerStack::create(new MockHandler([
            static fn (RequestInterface $request): never => throw new ConnectException('Connection refused', $request),
            static fn (RequestInterface $request): never => throw new ConnectException('Connection refused', $request),
            static fn (RequestInterface $request): never => throw new ConnectException('Connection refused', $request),
        ]));
        $stack->push(new ReleaseIdleDatabaseConnections, 'release_idle_database_connections');

        return (new InfluxDBFactory)->make($this->config([
            'httpClient' => new PluginClient(new Guzzle(['handler' => $stack]), [new RetryableNetworkErrors]),
        ]));
    }

    private function assertRetried(): void
    {
        $log = (string) file_get_contents($this->log);

        $this->assertSame(2, substr_count($log, 'The retryable error occurred'));
        $this->assertStringContainsString('Maximum retry attempts reached', $log);
    }
}
