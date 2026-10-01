<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\Tests\TestCase;

/**
 * The closure commands `workbench/routes/console.php` registers; they also prove
 * `workbench.discovers.commands` is wired, since without it none of them is found.
 *
 * @see workbench/routes/console.php
 */
class WorkbenchConsoleTest extends TestCase
{
    public function testConnectionsListsEveryConfiguredConnectionAndMarksTheDefault(): void
    {
        $this->artisan('influxdb:connections')
            ->expectsTable(
                ['Connection', 'URL', 'Bucket', 'Org'],
                [
                    ['main (default)', 'http://localhost:8086', 'main-bucket', 'main-org'],
                    ['analytics', 'http://analytics.localhost:8086', 'analytics-bucket', 'analytics-org'],
                ],
            )
            ->assertExitCode(0);
    }

    public function testShowFallsBackToTheDefaultConnection(): void
    {
        $this->artisan('influxdb:show')
            ->expectsOutputToContain('InfluxDB connection [main]')
            ->expectsOutputToContain('main-bucket')
            ->assertExitCode(0);
    }

    public function testShowResolvesTheNamedConnectionThroughTheManager(): void
    {
        $this->artisan('influxdb:show', ['connection' => 'analytics'])
            ->expectsOutputToContain('InfluxDB connection [analytics]')
            ->expectsOutputToContain('analytics-org')
            ->assertExitCode(0);

        $this->assertArrayHasKey(
            'analytics',
            $this->app->make(InfluxDBManager::class)->getConnections(),
        );
    }

    public function testShowFailsCleanlyForAnUnknownConnection(): void
    {
        $this->artisan('influxdb:show', ['connection' => 'missing'])
            ->expectsOutputToContain('InfluxDB connection [missing] is not configured.')
            ->assertExitCode(1);
    }

    public function testPreviewReportsTheMeasurementTagsAndFieldsOfTheBuiltPoint(): void
    {
        $this->artisan('influxdb:preview', [
            'measurement' => 'cpu',
            '--tag' => ['host=web1'],
            '--field' => ['value=0.64'],
        ])
            ->expectsOutputToContain('Measurement: cpu')
            ->expectsOutputToContain("host = 'web1'")
            ->expectsOutputToContain('value = 0.64')
            ->assertExitCode(0);
    }

    public function testPreviewRejectsAMalformedPair(): void
    {
        $this->artisan('influxdb:preview', [
            'measurement' => 'cpu',
            '--tag' => ['host'],
        ])
            ->expectsOutputToContain('Malformed --tag=[host]')
            ->assertExitCode(1);
    }

    public function testQueryPrintsTheStatementTheBuilderCompiles(): void
    {
        $this->artisan('influxdb:query', [
            'measurement' => 'cpu',
            '--select' => ['usage_user', 'host'],
            '--where' => ['host=web1', 'usage_user=0.5'],
            '--limit' => '10',
        ])
            ->expectsOutput('SELECT "usage_user", "host" FROM "cpu" WHERE "host" = \'web1\' AND "usage_user" = 0.5 LIMIT 10')
            ->assertExitCode(0);
    }

    public function testQueryGroupsByTimeOnTheNamedConnection(): void
    {
        $this->artisan('influxdb:query', [
            'measurement' => 'telegraf.autogen.cpu',
            '--connection' => 'analytics',
            '--group-by-time' => '1h',
        ])
            ->expectsOutput('SELECT * FROM "telegraf"."autogen"."cpu" GROUP BY time(1h)')
            ->assertExitCode(0);

        $this->assertArrayHasKey(
            'analytics',
            $this->app->make(InfluxDBManager::class)->getConnections(),
        );
    }

    public function testQueryRejectsAMalformedWhere(): void
    {
        $this->artisan('influxdb:query', [
            'measurement' => 'cpu',
            '--where' => ['host'],
        ])
            ->expectsOutputToContain('Malformed --where=[host], expected --where=key=value.')
            ->assertExitCode(1);
    }

    public function testQueryReportsWhatTheBuilderRefuses(): void
    {
        $this->artisan('influxdb:query', [
            'measurement' => 'cpu',
            '--group-by-time' => 'hourly',
        ])
            ->expectsOutputToContain('[hourly] is not an InfluxQL duration literal, such as 10m or 1h.')
            ->assertExitCode(1);

        $this->artisan('influxdb:query', [
            'measurement' => 'cpu',
            '--connection' => 'missing',
        ])
            ->expectsOutputToContain('InfluxDB connection [missing] is not configured.')
            ->assertExitCode(1);
    }
}
