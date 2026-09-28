<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB;

use InfluxDB2\Client;
use InvalidArgumentException;

class InfluxDBFactory
{
    /**
     * The connection config keys the upstream client requires.
     *
     * @var array<int, string>
     */
    private const REQUIRED_KEYS = ['url', 'token', 'bucket', 'org'];

    /**
     * Make a new InfluxDB client.
     *
     * Forwards the whole connection config to the upstream client instead of
     * a fixed key whitelist, so options the whitelist used to drop —
     * `httpClient`, `timeout`, `proxy`, `allow_redirects`, `tags`, `logFile`,
     * write options — reach it. Three keys are stripped first because they are
     * this package's, not the client's: `name`, which
     * InfluxDBManager::getConnectionConfig() adds for the manager's own use,
     * and `version` and `influxql`, which configure the InfluxQL connection.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException when a required config key is missing
     */
    public function make(array $config): Client
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (empty($config[$key])) {
                $name = $config['name'] ?? '?';

                throw new InvalidArgumentException("InfluxDB connection [{$name}] is missing the required [{$key}] config key.");
            }
        }

        unset($config['name'], $config['version'], $config['influxql']);

        return new Client($config + [
            'verifySSL' => true,
            'precision' => 'ns',
            'debug' => false,
        ]);
    }
}
