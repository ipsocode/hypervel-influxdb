<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\InfluxDB\InfluxQL\Result;
use Ipsocode\InfluxDB\InfluxQL\Series;
use Ipsocode\InfluxDB\Tests\TestCase;

class ResultTest extends TestCase
{
    #[UnitTest]
    public function testSeriesIsBuiltFromADecodedResponseEntry(): void
    {
        $series = Series::fromArray([
            'name' => 'cpu',
            'tags' => ['host' => 'web1'],
            'columns' => ['time', 'value'],
            'values' => [['2024-01-01T00:00:00Z', 0.64]],
            'partial' => true,
        ]);

        $this->assertSame('cpu', $series->name);
        $this->assertSame(['host' => 'web1'], $series->tags);
        $this->assertSame(['time', 'value'], $series->columns);
        $this->assertSame([['2024-01-01T00:00:00Z', 0.64]], $series->values);
        $this->assertTrue($series->partial);
    }

    #[UnitTest]
    public function testSeriesDefaultsTheKeysAResponseMayLeaveOut(): void
    {
        $series = Series::fromArray([]);

        $this->assertSame('', $series->name);
        $this->assertSame([], $series->tags);
        $this->assertSame([], $series->columns);
        $this->assertSame([], $series->values);
        $this->assertFalse($series->partial);
        $this->assertSame([], $series->rows());
    }

    #[UnitTest]
    public function testSeriesRowsAreObjectsKeyedByColumnWithTheTagsAppended(): void
    {
        $series = new Series('cpu', ['host' => 'web1'], ['time', 'value'], [
            ['2024-01-01T00:00:00Z', 0.64],
            ['2024-01-01T00:10:00Z', 0.42],
        ]);

        $rows = $series->rows();

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['time' => '2024-01-01T00:00:00Z', 'value' => 0.64, 'host' => 'web1'],
            (array) $rows[0],
        );
        $this->assertSame(
            ['time' => '2024-01-01T00:10:00Z', 'value' => 0.42, 'host' => 'web1'],
            (array) $rows[1],
        );
    }

    #[UnitTest]
    public function testAColumnWinsANameClashWithATag(): void
    {
        $series = new Series('cpu', ['host' => 'from-tag'], ['time', 'host'], [['2024-01-01T00:00:00Z', 'from-column']]);

        $this->assertSame('from-column', $series->rows()[0]->host);
    }

    #[UnitTest]
    public function testResultIsBuiltFromADecodedResponseEntry(): void
    {
        $result = Result::fromArray([
            'statement_id' => 2,
            'series' => [
                ['name' => 'cpu', 'columns' => ['time', 'value'], 'values' => [['2024-01-01T00:00:00Z', 1]]],
                ['name' => 'mem', 'columns' => ['time', 'value'], 'values' => [['2024-01-01T00:00:00Z', 2]]],
            ],
            'partial' => true,
        ]);

        $this->assertSame(2, $result->statementId);
        $this->assertCount(2, $result->series);
        $this->assertContainsOnlyInstancesOf(Series::class, $result->series);
        $this->assertSame('mem', $result->series[1]->name);
        $this->assertNull($result->error);
        $this->assertTrue($result->partial);
    }

    #[UnitTest]
    public function testResultDefaultsTheKeysAResponseMayLeaveOut(): void
    {
        $result = Result::fromArray([]);

        $this->assertSame(0, $result->statementId);
        $this->assertSame([], $result->series);
        $this->assertNull($result->error);
        $this->assertFalse($result->partial);
        $this->assertSame([], $result->rows());
    }

    #[UnitTest]
    public function testResultCarriesTheServersErrorForAStatementItRefused(): void
    {
        $result = Result::fromArray(['statement_id' => 0, 'error' => 'measurement not found']);

        $this->assertSame('measurement not found', $result->error);
        $this->assertSame([], $result->series);
    }

    #[UnitTest]
    public function testResultRowsFlattenEverySeriesInOrder(): void
    {
        $result = new Result(0, [
            new Series('cpu', ['host' => 'web1'], ['time', 'value'], [['t1', 1], ['t2', 2]]),
            new Series('cpu', ['host' => 'web2'], ['time', 'value'], [['t3', 3]]),
        ]);

        $rows = $result->rows();

        $this->assertCount(3, $rows);
        $this->assertSame(['web1', 'web1', 'web2'], array_column($rows, 'host'));
        $this->assertSame([1, 2, 3], array_column($rows, 'value'));
    }
}
