<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\Availability;

/**
 * The cooldowns the writers of a worker share: which connections are skipped for a while after they failed.
 */
class AvailabilityTest extends TestCase
{
    public function testAConnectionIsAvailableUntilItIsMarkedUnavailable(): void
    {
        $availability = new Availability;

        $this->assertTrue($availability->isAvailable('main'));
        $this->assertNull($availability->unavailableFor('main'));
        $this->assertSame([], $availability->unavailable());

        $availability->markUnavailable('main', 30);

        $this->assertFalse($availability->isAvailable('main'));
        $this->assertTrue($availability->isAvailable('backup'));
        $this->assertGreaterThan(29.0, $availability->unavailableFor('main'));
        $this->assertLessThanOrEqual(30.0, $availability->unavailableFor('main'));
        $this->assertSame(['main'], array_keys($availability->unavailable()));
    }

    public function testACooldownEndsOnItsOwn(): void
    {
        $availability = new Availability;

        $availability->markUnavailable('main', 0.1);
        $availability->markUnavailable('backup', 30);

        $this->assertFalse($availability->isAvailable('main'));

        usleep(150_000);

        $this->assertTrue($availability->isAvailable('main'));
        $this->assertSame(['backup'], array_keys($availability->unavailable()));
    }

    public function testMarkingAConnectionAvailableEndsItsCooldown(): void
    {
        $availability = new Availability;

        $availability->markUnavailable('main', 30);
        $availability->markAvailable('main');

        $this->assertTrue($availability->isAvailable('main'));

        $availability->markUnavailable('main', 30);
        $availability->markUnavailable('main', 0);

        $this->assertTrue($availability->isAvailable('main'));

        $availability->markUnavailable('main', -1);

        $this->assertTrue($availability->isAvailable('main'));
        $this->assertSame([], $availability->unavailable());
    }

    public function testANewCooldownReplacesTheOneRunning(): void
    {
        $availability = new Availability;

        $availability->markUnavailable('main', 30);
        $availability->markUnavailable('main', 1);

        $this->assertLessThanOrEqual(1.0, $availability->unavailableFor('main'));
    }
}
