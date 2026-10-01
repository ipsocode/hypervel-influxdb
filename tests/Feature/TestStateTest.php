<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\InfluxDB\InfluxQL\Builder;
use Ipsocode\InfluxDB\Support\RefPoint;
use Ipsocode\InfluxDB\Testing\TestState;
use Ipsocode\InfluxDB\Tests\TestCase;
use ReflectionProperty;

class TestStateTest extends TestCase
{
    public function testFlushStateFlushesRefPointStaticState(): void
    {
        $reflectionProperty = new ReflectionProperty(RefPoint::class, 'reflection');
        $before = $reflectionProperty->getValue(new RefPoint('cpu'));

        TestState::flushState();

        $after = $reflectionProperty->getValue(new RefPoint('cpu'));

        $this->assertNotSame($before, $after);
    }

    public function testRegisterWiresFlushStateIntoTheAfterEachCleanupCallbacks(): void
    {
        TestState::register();

        $reflectionProperty = new ReflectionProperty(RefPoint::class, 'reflection');
        $before = $reflectionProperty->getValue(new RefPoint('cpu'));

        AfterEachTestCleanup::runCallbacks();

        $after = $reflectionProperty->getValue(new RefPoint('cpu'));

        $this->assertNotSame($before, $after);
    }

    public function testFlushStateFlushesTheQueryBuilderMacros(): void
    {
        Builder::macro('onWebHosts', fn (): null => null);

        TestState::flushState();

        $this->assertFalse(Builder::hasMacro('onWebHosts'));
    }

    public function testTheRegisteredCleanupFlushesTheQueryBuilderMacros(): void
    {
        TestState::register();

        Builder::macro('onWebHosts', fn (): null => null);

        AfterEachTestCleanup::runCallbacks();

        $this->assertFalse(Builder::hasMacro('onWebHosts'));
    }
}
