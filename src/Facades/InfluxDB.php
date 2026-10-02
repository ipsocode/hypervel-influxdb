<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Facades;

use Hypervel\Support\Facades\Facade;

/**
 * Static access to the InfluxDBManager, and through it to the default connection's client.
 *
 * @method static \InfluxDB2\Client connection(?string $name = null)
 * @method static \InfluxDB2\Client reconnect(?string $name = null)
 * @method static void disconnect(?string $name = null)
 * @method static string getDefaultConnection()
 * @method static void setDefaultConnection(string $name)
 * @method static array<string, \InfluxDB2\Client> getConnections()
 * @method static array getConnectionConfig(string $name)
 * @method static string getConfigName()
 * @method static \Ipsocode\InfluxDB\InfluxDBFactory getFactory()
 * @method static \InfluxDB2\WriteApi writeApi(?string $connection = null, ?array $writeOptions = null)
 * @method static void flush(?string $name = null)
 * @method static void flushAll()
 * @method static \Ipsocode\InfluxDB\Write\Availability availability()
 * @method static \InfluxDB2\WriteApi createWriteApi(?array $writeOptions = null, ?array $pointSettings = null)
 * @method static \InfluxDB2\QueryApi createQueryApi()
 * @method static \Ipsocode\InfluxDB\InfluxQL\Connection influxql(?string $name = null)
 * @method static void detectServerVersions()
 * @method static null|string getDetectedServerVersion(string $name)
 * @method static \Ipsocode\InfluxDB\InfluxQL\Builder query(?string $connection = null)
 * @method static \Ipsocode\InfluxDB\InfluxQL\Builder table(\Ipsocode\InfluxDB\InfluxQL\Expression|\Ipsocode\InfluxDB\InfluxQL\Regex|string $measurement, ?string $connection = null)
 *
 * @see \Ipsocode\InfluxDB\InfluxDBManager
 * @see docs/hypervel.md#the-connection-manager
 */
class InfluxDB extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'influxdb';
    }
}
