<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Write;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InfluxDB2\ApiException;
use InvalidArgumentException;
use Ipsocode\InfluxDB\Tests\TestCase;
use Ipsocode\InfluxDB\Write\FailoverException;
use Ipsocode\InfluxDB\Write\FailoverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

class FailoverOptionsTest extends TestCase
{
    #[UnitTest]
    public function testAConnectionWithoutAFallbackHasNoFailover(): void
    {
        $this->assertNull(FailoverOptions::fromConfig([], 'main'));
        $this->assertNull(FailoverOptions::fromConfig(['fallback' => null, 'cooldown' => 5], 'main'));
        $this->assertNull(FailoverOptions::fromConfig(['fallback' => ''], 'main'));
        $this->assertNull(FailoverOptions::fromConfig(['fallback' => []], 'main'));
    }

    #[UnitTest]
    public function testFallbackIsANameAListOfNamesOrTheCommaSeparatedNamesEnvReads(): void
    {
        $this->assertSame(['backup'], FailoverOptions::fromConfig(['fallback' => 'backup'], 'main')->fallbacks);
        $this->assertSame(['backup', 'archive'], FailoverOptions::fromConfig(['fallback' => ['backup', 'archive']], 'main')->fallbacks);
        $this->assertSame(['backup', 'archive'], FailoverOptions::fromConfig(['fallback' => ' backup, archive ,'], 'main')->fallbacks);
        $this->assertSame(['backup', 'archive'], FailoverOptions::fromConfig(['fallback' => ['backup', 'archive', 'backup', '']], 'main')->fallbacks);
    }

    #[UnitTest]
    public function testCooldownDefaultsToThirtySecondsAndMayBeTheStringEnvReads(): void
    {
        $this->assertSame(FailoverOptions::DEFAULT_COOLDOWN, FailoverOptions::fromConfig(['fallback' => 'backup'], 'main')->cooldown);
        $this->assertSame(30.0, FailoverOptions::fromConfig(['fallback' => 'backup', 'cooldown' => null], 'main')->cooldown);
        $this->assertSame(0.5, FailoverOptions::fromConfig(['fallback' => 'backup', 'cooldown' => '0.5'], 'main')->cooldown);
        $this->assertSame(0.0, FailoverOptions::fromConfig(['fallback' => 'backup', 'cooldown' => 0], 'main')->cooldown);
    }

    #[UnitTest]
    #[DataProvider('invalidOptions')]
    public function testAnOptionOfTheWrongKindIsRefusedWithTheConnectionAndKey(array $writeOptions, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("InfluxDB connection [analytics] has an invalid write.{$message}.");

        FailoverOptions::fromConfig($writeOptions, 'analytics');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'fallback as a number' => [['fallback' => 5], 'fallback [5]; expected a connection name or a list of them'],
            'fallback as a boolean' => [['fallback' => true], 'fallback [true]; expected a connection name or a list of them'],
            'fallback as an object' => [['fallback' => new stdClass], 'fallback [stdClass]; expected a connection name or a list of them'],
            'fallback keyed by name' => [['fallback' => ['backup' => 'http://backup']], 'fallback [array]; expected a connection name or a list of them'],
            'fallback with a name that is not a string' => [['fallback' => ['backup', 5]], 'fallback [array]; expected a connection name or a list of them'],
            'fallback to itself' => [['fallback' => 'backup, analytics'], 'fallback [analytics]; expected the name of another connection'],
            'cooldown below 0' => [['fallback' => 'backup', 'cooldown' => -1], 'cooldown [-1]; expected a number of seconds, zero or above'],
            'cooldown in words' => [['fallback' => 'backup', 'cooldown' => 'slow'], 'cooldown [slow]; expected a number of seconds, zero or above'],
            'cooldown as a list' => [['fallback' => 'backup', 'cooldown' => [30]], 'cooldown [array]; expected a number of seconds, zero or above'],
        ];
    }

    #[UnitTest]
    public function testTheFailoverExceptionNamesWhatEachConnectionDid(): void
    {
        $failure = new ApiException('[503] Error connecting to the API (http://localhost:8086/api/v2/write)(unavailable)', 503);
        $refusal = new RuntimeException('Refused.');

        $taken = new FailoverException('main', 2, 27, 'main-bucket', ['main' => $failure, 'backup' => 12.34, 'archive' => $refusal], 'spare');

        $this->assertSame(
            'InfluxDB connection [main] failed to write 2 points (27 bytes) for bucket [main-bucket], which connection [spare] took: '
            . '[main] [503] Error connecting to the API (http://localhost:8086/api/v2/write)(unavailable); [backup] skipped for another 12.3 seconds; [archive] Refused.',
            $taken->getMessage(),
        );
        $this->assertSame($failure, $taken->getPrevious());
        $this->assertSame(['main' => $failure, 'archive' => $refusal], $taken->failures());
        $this->assertSame(['backup' => 12.34], $taken->skipped());
        $this->assertSame('spare', $taken->takenBy);

        $dropped = new FailoverException('main', 1, 13, 'main-bucket', ['main' => 3.0, 'backup' => $refusal]);

        $this->assertSame(
            'InfluxDB connection [main] failed to write 1 point (13 bytes) for bucket [main-bucket], and none of its fallbacks took them: '
            . '[main] skipped for another 3.0 seconds; [backup] Refused.',
            $dropped->getMessage(),
        );
        $this->assertNull($dropped->getPrevious());
        $this->assertNull($dropped->takenBy);
    }
}
