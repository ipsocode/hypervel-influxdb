<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use Hypervel\Support\Facades\DB;
use InfluxDB2\Client;
use Ipsocode\InfluxDB\InfluxDBFactory;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxDBServiceProvider;
use Ipsocode\InfluxDB\InfluxQL\Driver\Connection as InfluxQLDriverConnection;
use Ipsocode\InfluxDB\Tests\TestCase;

class InfluxDBServiceProviderTest extends TestCase
{
    public function testItRegistersTheManagerAsASingleton(): void
    {
        $this->assertInstanceOf(InfluxDBManager::class, $this->app->make('influxdb'));
        $this->assertInstanceOf(InfluxDBManager::class, $this->app->make(InfluxDBManager::class));
        $this->assertSame($this->app->make('influxdb'), $this->app->make(InfluxDBManager::class));
    }

    public function testItRegistersTheFactoryAsASingleton(): void
    {
        $this->assertInstanceOf(InfluxDBFactory::class, $this->app->make('influxdb.factory'));
        $this->assertInstanceOf(InfluxDBFactory::class, $this->app->make(InfluxDBFactory::class));
        $this->assertSame($this->app->make('influxdb.factory'), $this->app->make(InfluxDBFactory::class));
    }

    public function testItRegistersTheDefaultConnectionAsASingleton(): void
    {
        $this->assertInstanceOf(Client::class, $this->app->make('influxdb.connection'));
        $this->assertInstanceOf(Client::class, $this->app->make(Client::class));
        $this->assertSame($this->app->make('influxdb.connection'), $this->app->make(Client::class));
    }

    public function testTheDefaultConnectionMatchesTheManagersConnection(): void
    {
        $this->assertSame(
            $this->app->make('influxdb')->connection(),
            $this->app->make('influxdb.connection'),
        );
    }

    public function testItRegistersTheInfluxqlDatabaseDriver(): void
    {
        $this->app->get('config')->set('database.connections.metrics', ['driver' => 'influxql', 'connection' => 'main']);

        $connection = DB::connection('metrics');

        $this->assertInstanceOf(InfluxQLDriverConnection::class, $connection);
        $this->assertSame('main', $connection->getConfig('connection'));
        $this->assertSame('main-bucket', $connection->getDatabaseName());
    }

    public function testItMergesThePackagedConfigDefaults(): void
    {
        $this->assertSame('main', config('influxdb.default'));
        $this->assertIsArray(config('influxdb.connections.main'));
    }

    public function testMergingConnectionsKeepsThePackageDefaultWhenTheAppOnlyPublishesItsOwn(): void
    {
        $this->app->get('config')->set('influxdb.connections', [
            'custom' => ['url' => 'http://custom.localhost:8086'],
        ]);

        (new InfluxDBServiceProvider($this->app))->register();

        $this->assertArrayHasKey('custom', config('influxdb.connections'));
        $this->assertIsArray(config('influxdb.connections.main'));
        $this->assertSame('ns', config('influxdb.connections.main.precision'));
    }
}
