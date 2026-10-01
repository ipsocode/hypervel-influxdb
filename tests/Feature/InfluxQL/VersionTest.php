<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each version's label and the statements it runs, one row per version, so a version added to the enum
 * fails here until it has a row. GrammarTest shows each rule as the refusal a statement compiles to.
 */
class VersionTest extends TestCase
{
    #[UnitTest]
    #[DataProvider('versions')]
    public function testEachVersionSaysWhichStatementsItRuns(
        Version $version,
        string $label,
        bool $selectInto,
        bool $seriesLimits,
        bool $delete,
        bool $deletesFromDefaultRetentionPolicyOnly,
    ): void {
        $this->assertSame($label, $version->label());
        $this->assertSame($selectInto, $version->supportsSelectInto());
        $this->assertSame($seriesLimits, $version->supportsSeriesLimits());
        $this->assertSame($delete, $version->supportsDelete());
        $this->assertSame($deletesFromDefaultRetentionPolicyOnly, $version->deletesFromDefaultRetentionPolicyOnly());
    }

    /**
     * @return array<string, array{Version, string, bool, bool, bool, bool}>
     */
    public static function versions(): array
    {
        return [
            'v1' => [Version::V1, 'InfluxDB 1.x', true, true, true, false],
            'v2' => [Version::V2, 'InfluxDB 2.x', false, true, true, true],
            'v3' => [Version::V3, 'InfluxDB 3', false, false, false, false],
        ];
    }

    #[UnitTest]
    public function testEveryVersionHasARow(): void
    {
        $this->assertSame(Version::cases(), array_column(self::versions(), 0));
    }
}
