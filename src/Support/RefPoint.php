<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Support;

use InfluxDB2\Point;
use ReflectionClass;

/**
 * A readable wrapper around InfluxDB2\Point.
 *
 * Point stores its name, tags, and fields in private properties with no
 * getters. RefPoint uses reflection to read those values back out — useful for
 * inspecting or asserting against points after they have been built.
 */
class RefPoint extends Point
{
    private static ?ReflectionClass $pointReflection = null;

    private ReflectionClass $reflection;

    private Point $refPoint;

    public function __construct(
        $name,
        $tags = null,
        $fields = null,
        $time = null,
        $precision = Point::DEFAULT_WRITE_PRECISION,
    ) {
        parent::__construct($name, $tags, $fields, $time, $precision);

        $this->reflection = self::$pointReflection ??= new ReflectionClass(Point::class);
        $this->refPoint = $this;
    }

    /**
     * Create a RefPoint (or array of them) from existing Point(s).
     *
     * Reflection is used to access the private properties of the Point class.
     *
     * @param array<int, Point>|Point $point
     * @return array<int, self>|self
     */
    public static function from(Point|array $point): self|array
    {
        if ($point instanceof Point) {
            return self::fromPoint($point);
        }

        return array_map(
            static fn (Point $p): self => self::fromPoint($p),
            $point,
        );
    }

    private static function fromPoint(Point $point): self
    {
        $reflection = self::$pointReflection ??= new ReflectionClass(Point::class);

        $refPoint = new self(
            $reflection->getProperty('name')->getValue($point),
            $reflection->getProperty('tags')->getValue($point),
            $reflection->getProperty('fields')->getValue($point),
            $reflection->getProperty('time')->getValue($point),
            $point->getPrecision(),
        );

        return $refPoint->setRefPoint($point);
    }

    /**
     * Get the measurement (name) of the wrapped point.
     */
    public function getMeasurement(): string
    {
        return $this->getReflectedProperty('name');
    }

    /**
     * Get the tags of the wrapped point.
     *
     * @return null|array<string, string>
     */
    public function getTags(): ?array
    {
        return $this->getReflectedProperty('tags');
    }

    /**
     * Get the fields of the wrapped point.
     *
     * @return null|array<string, mixed>
     */
    public function getFields(): ?array
    {
        return $this->getReflectedProperty('fields');
    }

    public function setRefPoint(Point $refPoint): self
    {
        $this->refPoint = $refPoint;

        return $this;
    }

    private function getReflectedProperty(string $property): mixed
    {
        return $this->reflection->getProperty($property)->getValue($this->refPoint);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$pointReflection = null;
    }
}
