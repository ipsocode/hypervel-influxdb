<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Testing;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\Support\RefPoint;

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
