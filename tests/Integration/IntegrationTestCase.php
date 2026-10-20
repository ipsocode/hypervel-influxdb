<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use InfluxDB2\WriteType;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\QueryApi;
use Ipsocode\InfluxDB\Tests\TestCase;
use Throwable;

/**
 * A test against a real InfluxDB server, whose URL the environment names.
 *
 * Each class gets a database of its own, created before its tests and dropped after, so classes
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

        $this->admin("CREATE DATABASE \"{$this->database}\"");
    }

    protected function tearDown(): void
    {
        if (isset($this->app, $this->database)) {
            try {
                $this->admin("DROP DATABASE \"{$this->database}\"");
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

        $connection = [
            'version' => static::VERSION,
            'url' => $url,
            'token' => 'unused',
            'bucket' => "{$this->database}/autogen",
            'org' => '-',
            'precision' => 'ns',
            'logFile' => '/dev/null',
        ];

        $config->set('influxdb.connections.integration', $connection);
        $config->set('influxdb.connections.integration-batch', $connection + [
            'write' => ['writeType' => WriteType::BATCHING, 'flushInterval' => 0.2],
        ]);
        $config->set('database.connections.influxql', ['driver' => 'influxql', 'connection' => 'integration']);
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
