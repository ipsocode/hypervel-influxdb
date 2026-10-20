<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Hypervel\Support\Facades\DB;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\Version;

/**
 * The version the manager reads from a real InfluxDB 3 when the Hypervel server starts, which happens outside any coroutine.
 *
 * InfluxDB 3 names its version in the JSON body of `/ping`, where 1.x and 2.x name it in a header.
 */
class InfluxDBV3VersionDetectionTest extends V3IntegrationTestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testTheManagerReadsTheVersionOfTheServerBeforeTheWorkersFork(): void
    {
        $manager = $this->app->get(InfluxDBManager::class);

        $manager->detectServerVersions();

        $detected = (string) $manager->getDetectedServerVersion('integration');

        $this->assertMatchesRegularExpression('/^3\.\d+\.\d+/', $detected);
        $this->assertTrue(Version::V3->matches($detected));
        $this->assertSame($detected, $manager->influxql('integration')->getServerVersion());
        $this->assertTrue($manager->influxql('integration')->knowsServerVersion());
        $this->assertSame($detected, DB::connection('sql')->getServerVersion());
    }
}
