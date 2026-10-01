<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use InfluxDB2\Client;
use Ipsocode\InfluxDB\Facades\InfluxDB;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\Tests\TestCase;

class InfluxDBFacadeTest extends TestCase
{
    public function testItProxiesToTheUnderlyingManager(): void
    {
        $this->assertSame('main', InfluxDB::getDefaultConnection());
        $this->assertInstanceOf(Client::class, InfluxDB::connection());
        $this->assertSame(
            $this->app->make(InfluxDBManager::class)->connection(),
            InfluxDB::connection(),
        );
    }

    public function testItResolvesTheRegisteredManagerInstance(): void
    {
        $this->assertSame(
            $this->app->make(InfluxDBManager::class),
            InfluxDB::getFacadeRoot(),
        );
    }

    public function testItProxiesTheInfluxqlEntryPoints(): void
    {
        $manager = $this->app->make(InfluxDBManager::class);

        $this->assertSame($manager->influxql(), InfluxDB::influxql());
        $this->assertSame($manager->influxql('analytics'), InfluxDB::influxql('analytics'));
        $this->assertInstanceOf(Builder::class, InfluxDB::query());
        $this->assertSame($manager->influxql('analytics'), InfluxDB::query('analytics')->getConnection());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\'', InfluxDB::table('cpu')->where('host', 'web1')->toRawSql());
        $this->assertSame($manager->influxql('analytics'), InfluxDB::table('cpu', 'analytics')->getConnection());
    }
}
