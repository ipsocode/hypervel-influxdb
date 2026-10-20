<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use GuzzleHttp\Client;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;

/**
 * A test against a real InfluxDB 3 Core, whose URL the environment names.
 *
 * InfluxDB 3 has no retention policies and creates a database on its first write, so the class's
 * database is made and dropped through the configure API, and the bucket names it whole. The server
 * may run with authentication (`INFLUXDB_V3_TOKEN`) or without it, as CONTRIBUTING.md starts it.
 */
abstract class V3IntegrationTestCase extends IntegrationTestCase
{
    protected const string URL_VARIABLE = 'INFLUXDB_V3_URL';

    protected const string VERSION = 'v3';

    /**
     * Also configure the `sql` database connection, which runs SQL through the `influxdb` driver.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        if ($this->serverUrl() !== null) {
            $app->get('config')->set('database.connections.sql', ['driver' => 'influxdb', 'connection' => 'integration']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionConfig(string $url): array
    {
        return [
            'version' => static::VERSION,
            'url' => $url,
            'token' => $this->token(),
            'bucket' => $this->database,
            'org' => '-',
            'precision' => 'ns',
            'logFile' => '/dev/null',
        ];
    }

    protected function createDatabase(): void
    {
        $this->http()->post('/api/v3/configure/database', ['json' => ['db' => $this->database]]);
    }

    protected function dropDatabase(): void
    {
        $this->http()->delete('/api/v3/configure/database', ['query' => ['db' => $this->database]]);
    }

    /**
     * The token of the server, or `unused` for a server started without authentication.
     */
    protected function token(): string
    {
        $token = getenv('INFLUXDB_V3_TOKEN');

        return is_string($token) && $token !== '' ? $token : 'unused';
    }

    /**
     * A client of its own for the administrative calls, which run outside the test's coroutine.
     */
    private function http(): Client
    {
        return new Client([
            'base_uri' => (string) $this->serverUrl(),
            'headers' => ['Authorization' => 'Bearer ' . $this->token()],
        ]);
    }
}
