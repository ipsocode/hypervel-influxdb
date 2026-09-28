<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use InfluxDB2\Point;
use Ipsocode\InfluxDB\Support\RefPoint;
use Ipsocode\InfluxDB\Tests\TestCase;
use ReflectionProperty;

class RefPointTest extends TestCase
{
    public function testFromExposesAPointsMeasurementTagsAndFields(): void
    {
        $point = Point::measurement('cpu')
            ->addTag('host', 'web1')
            ->addField('value', 0.64);

        $ref = RefPoint::from($point);

        $this->assertInstanceOf(RefPoint::class, $ref);
        $this->assertSame('cpu', $ref->getMeasurement());
        $this->assertSame(['host' => 'web1'], $ref->getTags());
        $this->assertSame(['value' => 0.64], $ref->getFields());
    }

    public function testFromAcceptsAnArrayOfPointsAndReturnsAnArrayOfRefPoints(): void
    {
        $points = [
            Point::measurement('cpu')->addTag('host', 'web1')->addField('value', 0.64),
            Point::measurement('memory')->addTag('host', 'web2')->addField('value', 0.42),
        ];

        $refs = RefPoint::from($points);

        $this->assertIsArray($refs);
        $this->assertCount(2, $refs);
        $this->assertContainsOnlyInstancesOf(RefPoint::class, $refs);

        $this->assertSame('cpu', $refs[0]->getMeasurement());
        $this->assertSame(['host' => 'web1'], $refs[0]->getTags());
        $this->assertSame(['value' => 0.64], $refs[0]->getFields());

        $this->assertSame('memory', $refs[1]->getMeasurement());
        $this->assertSame(['host' => 'web2'], $refs[1]->getTags());
        $this->assertSame(['value' => 0.42], $refs[1]->getFields());
    }

    public function testConstructingDirectlyExposesTheOwnMeasurementTagsAndFields(): void
    {
        $ref = new RefPoint('cpu', ['host' => 'web1'], ['value' => 0.64]);

        $this->assertSame('cpu', $ref->getMeasurement());
        $this->assertSame(['host' => 'web1'], $ref->getTags());
        $this->assertSame(['value' => 0.64], $ref->getFields());
    }

    public function testReflectionClassIsMemoisedAcrossInstances(): void
    {
        $reflectionProperty = new ReflectionProperty(RefPoint::class, 'reflection');

        $first = new RefPoint('cpu');
        $second = RefPoint::from(Point::measurement('memory'));

        $this->assertSame($reflectionProperty->getValue($first), $reflectionProperty->getValue($second));
    }

    public function testFlushStateResetsTheMemoisedReflectionClass(): void
    {
        $reflectionProperty = new ReflectionProperty(RefPoint::class, 'reflection');

        $before = $reflectionProperty->getValue(new RefPoint('cpu'));

        RefPoint::flushState();

        $after = $reflectionProperty->getValue(new RefPoint('cpu'));

        $this->assertNotSame($before, $after);
    }
}
