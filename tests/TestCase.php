<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase as BaseTestCase;

/**
 * Base test case for the package suite.
 *
 * The test environment lives in the Workbench application rather than here:
 * `workbench/config/influxdb.php` defines the connections, and `testbench.yaml`
 * turns on the Workbench surfaces that load it. That means the suite resolves
 * config the same way a consuming application does — through Testbench's
 * configuration loading and the provider's `mergeConfigFrom()` — instead of
 * through values pushed in from a `defineEnvironment()` hook after the fact.
 *
 * @see testbench.yaml
 * @see workbench/config/influxdb.php
 */
abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;
}
