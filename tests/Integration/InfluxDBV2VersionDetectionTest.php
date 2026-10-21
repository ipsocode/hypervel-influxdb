<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * The version the manager reads from a real InfluxDB 2.x when the Hypervel server starts, which happens outside any coroutine.
 */
class InfluxDBV2VersionDetectionTest extends V2IntegrationTestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testTheManagerReadsTheVersionOfTheServerBeforeTheWorkersFork(): void
    {
        $manager = $this->app->get(InfluxDBManager::class);

        $manager->detectServerVersions();

        $detected = (string) $manager->getDetectedServerVersion('integration');

        $this->assertMatchesRegularExpression('/^v2\.\d+\.\d+/', $detected);
        $this->assertTrue(Version::V2->matches($detected));
        $this->assertSame($detected, $manager->influxql('integration')->getServerVersion());
        $this->assertTrue($manager->influxql('integration')->knowsServerVersion());
    }
}
