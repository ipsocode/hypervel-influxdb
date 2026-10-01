<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InfluxDB2\WriteType;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\Fixtures\RecordsFailures;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\Batch;
use Ipsocode\InfluxDB\Write\BatchOptions;
use Ipsocode\InfluxDB\Write\BatchWriteException;
use Ipsocode\InfluxDB\Write\BufferFullException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

class BatchOptionsTest extends TestCase
{
    #[UnitTest]
    #[DataProvider('versions')]
    public function testTheLimitsDefaultToInfluxDatasAdviceForTheVersion(Version $version, int $lines, int $bytes, int $maxBuffered): void
    {
        $options = BatchOptions::fromConfig(['writeType' => WriteType::BATCHING], $version, 'main');

        $this->assertSame(
            [$lines, $bytes, 1.0, $maxBuffered, BatchOptions::FLUSH, null],
            [$options->batchSize, $options->batchBytes, $options->flushInterval, $options->maxBuffered, $options->overflow, $options->onFailure],
        );
    }

    /**
     * InfluxData's advice for each version: lines, bytes, and the ten batches held by default.
     *
     * @return array<string, array{Version, int, int, int}>
     */
    public static function versions(): array
    {
        return [
            'v1: 5,000 lines or 25 MB, 1.x\'s max-body-size' => [Version::V1, 5_000, 25_000_000, 50_000],
            'v2: 5,000 lines or 50 MB, InfluxDB Cloud\'s limit' => [Version::V2, 5_000, 50_000_000, 50_000],
            'v3: 10,000 lines or 10 MB' => [Version::V3, 10_000, 10_000_000, 100_000],
        ];
    }

    #[UnitTest]
    public function testANullOptionTakesItsDefault(): void
    {
        $options = BatchOptions::fromConfig(
            ['batchSize' => null, 'batchSizeMb' => null, 'flushInterval' => null, 'maxBuffered' => null, 'overflow' => null, 'onFailure' => null],
            Version::V3,
            'main',
        );

        $this->assertSame([10_000, 10_000_000, 1.0, 100_000], [$options->batchSize, $options->batchBytes, $options->flushInterval, $options->maxBuffered]);
    }

    #[UnitTest]
    public function testTheLimitsCanBeSetInLinesMegabytesAndSeconds(): void
    {
        $onFailure = static function (): void {
        };

        $options = BatchOptions::fromConfig([
            'batchSize' => 2_000,
            'batchSizeMb' => 1.5,
            'flushInterval' => 0.25,
            'maxBuffered' => 4_000,
            'overflow' => BatchOptions::REFUSE,
            'onFailure' => $onFailure,
        ], Version::V1, 'main');

        $this->assertSame(
            [2_000, 1_500_000, 0.25, 4_000, BatchOptions::REFUSE, $onFailure],
            [$options->batchSize, $options->batchBytes, $options->flushInterval, $options->maxBuffered, $options->overflow, $options->onFailure],
        );
    }

    #[UnitTest]
    public function testMaxBufferedDefaultsToTenBatches(): void
    {
        $this->assertSame(1_230, BatchOptions::fromConfig(['batchSize' => 123], Version::V2, 'main')->maxBuffered);
    }

    #[UnitTest]
    public function testNumbersMayBeTheStringsEnvReads(): void
    {
        $options = BatchOptions::fromConfig(
            ['batchSize' => '2000', 'batchSizeMb' => '0.5', 'flushInterval' => '2', 'maxBuffered' => '6000'],
            Version::V2,
            'main',
        );

        $this->assertSame([2_000, 500_000, 2.0, 6_000], [$options->batchSize, $options->batchBytes, $options->flushInterval, $options->maxBuffered]);
    }

    #[UnitTest]
    public function testATinyBatchSizeMbIsStillOneByte(): void
    {
        $this->assertSame(1, BatchOptions::fromConfig(['batchSizeMb' => 0.0000001], Version::V1, 'main')->batchBytes);
    }

    #[UnitTest]
    public function testOnFailureMayBeAnyCallableOrTheNameOfAnInvokableClass(): void
    {
        foreach (['strlen', [self::class, 'versions'], new RecordsFailures, RecordsFailures::class] as $onFailure) {
            $this->assertSame($onFailure, BatchOptions::fromConfig(['onFailure' => $onFailure], Version::V1, 'main')->onFailure);
        }
    }

    #[UnitTest]
    #[DataProvider('invalidOptions')]
    public function testAnOptionOutOfRangeIsRefusedWithTheConnectionAndKey(string $key, mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("InfluxDB connection [analytics] has an invalid write.{$key} {$message}.");

        BatchOptions::fromConfig([$key => $value], Version::V1, 'analytics');
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'batchSize of 0' => ['batchSize', 0, '[0]; expected a whole number above zero'],
            'batchSize below 0' => ['batchSize', -5, '[-5]; expected a whole number above zero'],
            'fractional batchSize' => ['batchSize', 1.5, '[1.5]; expected a whole number above zero'],
            'batchSize in words' => ['batchSize', 'many', '[many]; expected a whole number above zero'],
            'batchSize as a boolean' => ['batchSize', true, '[true]; expected a whole number above zero'],
            'batchSizeMb of 0' => ['batchSizeMb', 0, '[0]; expected a number above zero'],
            'batchSizeMb in words' => ['batchSizeMb', 'ten', '[ten]; expected a number above zero'],
            'flushInterval of 0' => ['flushInterval', 0, '[0]; expected a number above zero'],
            'flushInterval below 0' => ['flushInterval', -1, '[-1]; expected a number above zero'],
            'maxBuffered of 0' => ['maxBuffered', 0, '[0]; expected a whole number above zero'],
            'an unknown overflow' => ['overflow', 'drop', '[drop]; expected flush or refuse'],
            'an overflow that is not a string' => ['overflow', [], '[array]; expected flush or refuse'],
            'onFailure naming no class' => ['onFailure', 'App\Missing', '[App\Missing]; expected a callable or the name of an invokable class'],
            'onFailure naming a class that is not invokable' => ['onFailure', stdClass::class, '[stdClass]; expected a callable or the name of an invokable class'],
            'onFailure that is not callable' => ['onFailure', new stdClass, '[stdClass]; expected a callable or the name of an invokable class'],
        ];
    }

    /**
     * @param null|array<string, mixed> $writeOptions
     */
    #[UnitTest]
    #[DataProvider('writeTypes')]
    public function testWriteTypeIsTheSwitch(?array $writeOptions, bool $batching): void
    {
        $this->assertSame($batching, BatchOptions::batching($writeOptions));
    }

    /**
     * @return array<string, array{null|array<string, mixed>, bool}>
     */
    public static function writeTypes(): array
    {
        return [
            'no write options' => [null, false],
            'no writeType' => [[], false],
            'SYNCHRONOUS' => [['writeType' => WriteType::SYNCHRONOUS], false],
            'BATCHING' => [['writeType' => WriteType::BATCHING], true],
            'BATCHING as a numeric string' => [['writeType' => '2'], true],
            'a name, which the client does not read either' => [['writeType' => 'batching'], false],
        ];
    }

    #[UnitTest]
    public function testTheExceptionsNameOnePointInTheSingular(): void
    {
        $batch = new Batch('main', 'main-bucket', 'main-org', 'ns', 'cpu load=1i 1', 1);

        $this->assertSame(
            'InfluxDB connection [main] dropped a batch of 1 point (13 bytes) for bucket [main-bucket]: Unreachable.',
            (new BatchWriteException($batch, new RuntimeException('Unreachable.')))->getMessage(),
        );
        $this->assertSame(
            'InfluxDB connection [main] refused 1 point: 9 are waiting to be sent, and its maxBuffered is 10.',
            (new BufferFullException('main', 1, 9, 10))->getMessage(),
        );
    }
}
