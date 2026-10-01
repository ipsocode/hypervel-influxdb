<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase as BaseTestCase;

/**
 * Base test case: boots the Workbench application, whose `workbench/config/influxdb.php` defines
 * the connections every test shares, as an application's config would.
 *
 * @see testbench.yaml
 * @see workbench/config/influxdb.php
 */
abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;
}
