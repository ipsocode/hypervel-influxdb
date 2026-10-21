<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB;

use GuzzleHttp\Client as Guzzle;
use Http\Client\Common\PluginClient;
use InfluxDB2\Client;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Support\RetryableNetworkErrors;

class InfluxDBFactory
{
    /**
     * The connection config keys every connection must set.
     *
     * @var array<int, string>
     */
    private const REQUIRED_KEYS = ['url', 'token', 'bucket', 'org'];

    /**
     * Make a new InfluxDB client.
     *
     * Passes the whole connection config to InfluxDB2\Client, so every client option reaches it,
     * after removing this package's own keys: `name`, `version` and `influxql`.
     *
     * Unless the connection sets its own `httpClient`, the client gets a Guzzle one with the
     * connection's `timeout`, `verifySSL` and `proxy`, whose network failures the client's write
     * retries then see as network errors.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException when a required config key is missing
     *
     * @see docs/configuration.md#passing-options-to-the-client
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

        $config += [
            'verifySSL' => true,
            'precision' => 'ns',
            'debug' => false,
        ];

        if (empty($config['httpClient'])) {
            $config['httpClient'] = new PluginClient(new Guzzle([
                'timeout' => $config['timeout'] ?? 10,
                'verify' => $config['verifySSL'],
                'proxy' => $config['proxy'] ?? null,
            ]), [new RetryableNetworkErrors]);
        }

        return new Client($config);
    }
}
