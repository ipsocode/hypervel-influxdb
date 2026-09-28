<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use InfluxDB2\Client;
use InfluxDB2\WriteApi;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxDBFactory;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\InfluxQL\Connection;
use Ipsocode\InfluxDB\InfluxQL\V1Connection;
use Ipsocode\InfluxDB\InfluxQL\V2Connection;
use Ipsocode\InfluxDB\InfluxQL\V3Connection;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\TestCase;

class InfluxDBManagerTest extends TestCase
{
    public function testConnectionReturnsAClientForTheDefaultConnection(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertInstanceOf(Client::class, $manager->connection());
        $this->assertInstanceOf(Client::class, $manager->connection('main'));
    }

    public function testConnectionsAreCachedByName(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertSame($manager->connection('main'), $manager->connection('main'));
        $this->assertSame($manager->connection(), $manager->connection('main'));
    }

    public function testDifferentlyNamedConnectionsAreDistinctInstances(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertNotSame($manager->connection('main'), $manager->connection('analytics'));
    }

    public function testUnknownConnectionThrowsAnException(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [missing] is not configured.');

        $manager->connection('missing');
    }

    public function testGetConnectionConfigIncludesTheConnectionName(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $config = $manager->getConnectionConfig('analytics');

        $this->assertSame('analytics', $config['name']);
        $this->assertSame('analytics-bucket', $config['bucket']);
    }

    public function testDefaultConnectionNameCanBeReadAndChanged(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertSame('main', $manager->getDefaultConnection());

        $manager->setDefaultConnection('analytics');

        $this->assertSame('analytics', $manager->getDefaultConnection());
        $this->assertSame('analytics', config('influxdb.default'));
    }

    public function testDisconnectForgetsTheCachedConnection(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $first = $manager->connection('main');
        $manager->disconnect('main');

        $this->assertArrayNotHasKey('main', $manager->getConnections());
        $this->assertNotSame($first, $manager->connection('main'));
    }

    public function testReconnectReplacesTheCachedConnection(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $first = $manager->connection('main');
        $second = $manager->reconnect('main');

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $manager->connection('main'));
    }

    public function testGetConnectionsReflectsTheResolvedCache(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertSame([], $manager->getConnections());

        $connection = $manager->connection('main');

        $this->assertSame(['main' => $connection], $manager->getConnections());
    }

    public function testGetFactoryReturnsTheUnderlyingFactory(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertInstanceOf(InfluxDBFactory::class, $manager->getFactory());
        $this->assertSame($this->app->make(InfluxDBFactory::class), $manager->getFactory());
    }

    public function testCallProxiesToTheDefaultConnection(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $writeApi = $manager->createWriteApi();

        $this->assertSame($manager->connection()->createWriteApi()::class, $writeApi::class);
    }

    public function testWriteApiReturnsTheSameInstanceOnRepeatCalls(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $writeApi = $manager->writeApi();

        $this->assertInstanceOf(WriteApi::class, $writeApi);
        $this->assertSame($writeApi, $manager->writeApi());
        $this->assertSame($writeApi, $manager->writeApi('main'));
    }

    public function testWriteApiIsMemoisedSeparatelyPerConnection(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertNotSame($manager->writeApi('main'), $manager->writeApi('analytics'));
    }

    public function testWriteApiAcceptsExplicitWriteOptions(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $writeApi = $manager->writeApi('main', ['batchSize' => 500]);

        $this->assertInstanceOf(WriteApi::class, $writeApi);
    }

    public function testInfluxqlIsMemoisedPerConnectionOnTopOfItsClient(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $influxql = $manager->influxql();

        $this->assertInstanceOf(Connection::class, $influxql);
        $this->assertSame($influxql, $manager->influxql());
        $this->assertSame($influxql, $manager->influxql('main'));
        $this->assertNotSame($influxql, $manager->influxql('analytics'));
        $this->assertSame($manager->connection('main'), $influxql->getClient());
    }

    public function testInfluxqlIsConfiguredFromTheConnectionsConfig(): void
    {
        $this->app->get('config')->set('influxdb.connections.analytics.influxql', ['retentionPolicy' => 'weekly', 'epoch' => 'ms']);

        $influxql = $this->app->make(InfluxDBManager::class)->influxql('analytics');

        $this->assertInstanceOf(V1Connection::class, $influxql);
        $this->assertSame('analytics', $influxql->getName());
        $this->assertSame(Version::V1, $influxql->getVersion());
        $this->assertSame('analytics-bucket', $influxql->getDatabase());
        $this->assertSame('weekly', $influxql->getRetentionPolicy());
        $this->assertSame('ms', $influxql->getEpoch());
    }

    public function testInfluxqlCompilesForTheVersionTheConnectionNames(): void
    {
        $this->app->get('config')->set('influxdb.connections.analytics.version', 'v2');

        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertInstanceOf(V2Connection::class, $manager->influxql('analytics'));
        $this->assertSame(Version::V2, $manager->influxql('analytics')->getVersion());
        $this->assertInstanceOf(V1Connection::class, $manager->influxql('main'));
    }

    public function testInfluxqlCompilesForInfluxdb3OnAV3Connection(): void
    {
        $this->app->get('config')->set('influxdb.connections.analytics.version', 'v3');
        $this->app->get('config')->set('influxdb.connections.analytics.bucket', 'telegraf/autogen');

        $influxql = $this->app->make(InfluxDBManager::class)->influxql('analytics');

        $this->assertInstanceOf(V3Connection::class, $influxql);
        $this->assertSame(Version::V3, $influxql->getVersion());
        $this->assertSame('telegraf/autogen', $influxql->getDatabase());
        $this->assertNull($influxql->getRetentionPolicy());
    }

    public function testInfluxqlRefusesAVersionThePackageDoesNotImplement(): void
    {
        $this->app->get('config')->set('influxdb.connections.analytics.version', 'v4');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [analytics] has an unsupported version [v4]; expected one of v1, v2, v3.');

        $this->app->make(InfluxDBManager::class)->influxql('analytics');
    }

    public function testInfluxqlForAnUnknownConnectionThrowsAnException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('InfluxDB connection [missing] is not configured.');

        $this->app->make(InfluxDBManager::class)->influxql('missing');
    }

    public function testDisconnectDropsTheWriteApiAndInfluxqlBuiltOnTheClient(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $writeApi = $manager->writeApi('main');
        $influxql = $manager->influxql('main');
        $analytics = $manager->influxql('analytics');

        $manager->disconnect('main');

        $this->assertNotSame($writeApi, $manager->writeApi('main'));
        $this->assertNotSame($influxql, $manager->influxql('main'));
        $this->assertSame($analytics, $manager->influxql('analytics'));
    }

    public function testReconnectRebuildsTheInfluxqlConnectionOnTheNewClient(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $influxql = $manager->influxql();
        $client = $manager->reconnect();

        $this->assertNotSame($influxql, $manager->influxql());
        $this->assertSame($client, $manager->influxql()->getClient());
    }

    public function testQueryStartsABuilderOnTheNamedConnection(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $query = $manager->query();

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertNull($query->from);
        $this->assertSame($manager->influxql(), $query->getConnection());
        $this->assertSame($manager->influxql('analytics'), $manager->query('analytics')->getConnection());
    }

    public function testTableStartsABuilderOnAMeasurement(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $query = $manager->table('cpu', 'analytics');

        $this->assertSame('cpu', $query->from);
        $this->assertSame($manager->influxql('analytics'), $query->getConnection());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = ?', $manager->table('cpu')->where('host', 'web1')->toSql());
    }
}
