<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Testing;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\Support\RefPoint;

/**
 * Resets the package's static state after each test.
 *
 * @see docs/hypervel.md#testing-an-application-that-uses-it
 */
class TestState
{
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('ipsocode/hypervel-influxdb', fn () => static::flushState());
    }

    public static function flushState(): void
    {
        RefPoint::flushState();
        Builder::flushState();
    }
}
