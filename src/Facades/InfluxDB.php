<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Facades;

use Hypervel\Support\Facades\Facade;

/**
 * @method static \InfluxDB2\Client connection(?string $name = null)
 * @method static \InfluxDB2\Client reconnect(?string $name = null)
 * @method static void disconnect(?string $name = null)
 * @method static string getDefaultConnection()
 * @method static void setDefaultConnection(string $name)
 * @method static array getConnections()
 * @method static \Ipsocode\InfluxDB\InfluxDBFactory getFactory()
 * @method static \InfluxDB2\WriteApi writeApi(?string $connection = null, ?array $writeOptions = null)
 * @method static void flush(?string $name = null)
 * @method static void flushAll()
 * @method static \InfluxDB2\WriteApi createWriteApi(?array $writeOptions = null, ?array $pointSettings = null)
 * @method static \InfluxDB2\QueryApi createQueryApi()
 * @method static \Ipsocode\InfluxDB\InfluxQL\Connection influxql(?string $name = null)
 * @method static \Ipsocode\InfluxDB\InfluxQL\Builder query(?string $connection = null)
 * @method static \Ipsocode\InfluxDB\InfluxQL\Builder table(\Ipsocode\InfluxDB\InfluxQL\Expression|\Ipsocode\InfluxDB\InfluxQL\Regex|string $measurement, ?string $connection = null)
 *
 * @see \Ipsocode\InfluxDB\InfluxDBManager
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
