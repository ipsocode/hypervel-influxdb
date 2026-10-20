<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Ipsocode\InfluxDB\InfluxDBManager;

/**
 * The version the manager reads from a real InfluxDB 1.x when the Hypervel server starts, which happens outside any coroutine.
 */
class InfluxDBV1VersionDetectionTest extends IntegrationTestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testTheManagerReadsTheVersionOfTheServerBeforeTheWorkersFork(): void
    {
        $manager = $this->app->get(InfluxDBManager::class);

        $manager->detectServerVersions();

        $this->assertMatchesRegularExpression('/^v?1\.\d+\.\d+/', (string) $manager->getDetectedServerVersion('integration'));
        $this->assertSame($manager->getDetectedServerVersion('integration'), $manager->influxql('integration')->getServerVersion());
        $this->assertTrue($manager->influxql('integration')->knowsServerVersion());
    }
}
