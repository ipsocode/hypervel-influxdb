<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Fixtures;

/**
 * A backed enum to bind, standing in for the tag values an application keeps as one.
 */
enum Region: string
{
    case Europe = 'eu';
    case America = 'us';
}
