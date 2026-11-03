<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigFileTest extends TestCase
{
    /**
     * @param array<string, string> $environment
     * @param array<string, mixed> $expected
     */
    #[DataProvider('environments')]
    public function testEnvironmentValuesReachTheConnectionAsTheirTypes(array $environment, array $expected): void
    {
        $names = array_keys($environment);

        foreach ($environment as $name => $value) {
            putenv("{$name}={$value}");
        }

        try {
            $main = (require dirname(__DIR__, 2) . '/config/influxdb.php')['connections']['main'];
        } finally {
            foreach ($names as $name) {
                putenv($name);
            }
        }

        foreach ($expected as $key => $value) {
            $actual = $key === 'verifySSL' || $key === 'debug' ? $main[$key] : $main['write'][$key];

            $this->assertSame($value, $actual, $key);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>}>
     */
    public static function environments(): iterable
    {
        yield 'verify off as 0' => [['INFLUXDB_VERIFY_SSL' => '0'], ['verifySSL' => false]];
        yield 'verify off as off' => [['INFLUXDB_VERIFY_SSL' => 'off'], ['verifySSL' => false]];
        yield 'verify on as 1' => [['INFLUXDB_VERIFY_SSL' => '1'], ['verifySSL' => true]];
        yield 'verify stays on for an unreadable value' => [['INFLUXDB_VERIFY_SSL' => 'maybe'], ['verifySSL' => true]];
        yield 'debug on as 1' => [['INFLUXDB_DEBUG' => '1'], ['debug' => true]];
        yield 'debug off for an unreadable value' => [['INFLUXDB_DEBUG' => 'maybe'], ['debug' => false]];
        yield 'numbers' => [
            ['INFLUXDB_BATCH_SIZE' => '250', 'INFLUXDB_BATCH_SIZE_MB' => '1.5', 'INFLUXDB_FLUSH_INTERVAL' => '2', 'INFLUXDB_COOLDOWN' => '30'],
            ['batchSize' => 250, 'batchSizeMb' => 1.5, 'flushInterval' => 2, 'cooldown' => 30],
        ];
        yield 'a number that is not one is left for the write options to refuse' => [['INFLUXDB_BATCH_SIZE' => 'many'], ['batchSize' => 'many']];
        yield 'unset numbers' => [[], ['batchSize' => null, 'cooldown' => null]];
    }
}
