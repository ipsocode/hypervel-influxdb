<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use InfluxDB2\WriteType;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\QueryApi;
use Ipsocode\InfluxDB\Tests\Integration\Support\FaultyProxy;
use Ipsocode\InfluxDB\Tests\TestCase;
use Throwable;

/**
 * A test against a real InfluxDB server, whose URL the environment names.
 *
 * Each test gets a database of its own, created before it and dropped after, so tests
 * are independent and can run again against the same server. Without the variable the tests skip.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * The environment variable that holds the URL of the server, such as `http://localhost:8086`.
     */
    protected const string URL_VARIABLE = 'INFLUXDB_V1_URL';

    /**
     * The InfluxDB version the connection is configured for.
     */
    protected const string VERSION = 'v1';

    protected string $database;

    protected function setUp(): void
    {
        if ($this->serverUrl() === null) {
            $this->markTestSkipped(static::URL_VARIABLE . ' is not set.');
        }

        $this->database = 'it_' . bin2hex(random_bytes(6));

        parent::setUp();

        $this->createDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->app, $this->database)) {
            try {
                $this->dropDatabase();
            } catch (Throwable) {
                // The server is gone or the database never existed; there is nothing to drop.
            }
        }

        parent::tearDown();
    }

    /**
     * Configure the `integration` connection on the server, the `integration-batch` one that batches its writes, and the `influxql` database connection.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $url = $this->serverUrl();

        if ($url === null) {
            return;
        }

        $config = $app->get('config');

        $connection = $this->connectionConfig($url);

        $config->set('influxdb.connections.integration', $connection);
        $config->set('influxdb.connections.integration-batch', $connection + [
            'write' => ['writeType' => WriteType::BATCHING, 'flushInterval' => 0.2],
        ]);
        $config->set('database.connections.influxql', ['driver' => 'influxql', 'connection' => 'integration']);
    }

    /**
     * The `integration` connection's settings, for a server at the given URL.
     *
     * @return array<string, mixed>
     */
    protected function connectionConfig(string $url): array
    {
        return [
            'version' => static::VERSION,
            'url' => $url,
            'token' => 'unused',
            'bucket' => "{$this->database}/autogen",
            'org' => '-',
            'precision' => 'ns',
            'logFile' => '/dev/null',
        ];
    }

    /**
     * Create the test's database on the server.
     */
    protected function createDatabase(): void
    {
        $this->admin("CREATE DATABASE \"{$this->database}\"");
    }

    /**
     * Drop the test's database from the server.
     */
    protected function dropDatabase(): void
    {
        $this->admin("DROP DATABASE \"{$this->database}\"");
    }

    /**
     * Run an administrative statement on a transport of its own.
     *
     * setUp() and tearDown() run outside the coroutine the test body runs in, and an HTTP client
     * made outside it cannot be used inside it, so the connections the tests use are not touched here.
     */
    protected function admin(string $statement): void
    {
        $options = $this->app->get(InfluxDBManager::class)->getFactory()
            ->make($this->app->get(InfluxDBManager::class)->getConnectionConfig('integration'))->options;

        (new QueryApi($options))->query($statement);
    }

    /**
     * A proxy in front of the server under test, listening in this coroutine.
     */
    protected function proxy(int $drop = 0): FaultyProxy
    {
        $url = parse_url((string) $this->serverUrl());

        return new FaultyProxy((string) $url['host'], (int) $url['port'], $drop);
    }

    /**
     * The `integration` connection pointed at the proxy.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function proxiedConfig(FaultyProxy $proxy, array $extra = [], ?int $port = null): array
    {
        return $extra + $this->connectionConfig('http://127.0.0.1:' . ($port ?? $proxy->start()));
    }

    /**
     * The URL of the server under test, or null when the environment names none.
     */
    protected function serverUrl(): ?string
    {
        $url = getenv(static::URL_VARIABLE);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Run the callback until it returns true, for at most the given seconds.
     */
    protected function eventually(callable $callback, float $seconds = 10.0): void
    {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);

        while (! $callback()) {
            if (hrtime(true) > $deadline) {
                $this->fail("The condition did not hold within {$seconds} seconds.");
            }

            usleep(50_000);
        }
    }
}
