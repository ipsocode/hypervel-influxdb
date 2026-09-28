<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InfluxDB2\ApiException;
use Ipsocode\InfluxDB\InfluxQL\QueryException;
use Ipsocode\InfluxDB\Tests\TestCase;

class QueryExceptionTest extends TestCase
{
    #[UnitTest]
    public function testAServerErrorMessageNamesTheConnectionAndTheStatementAsSent(): void
    {
        $exception = new QueryException('analytics', 'SELECT * FROM "cpu" WHERE "host" = \'web1\'', ['web1'], 'measurement not found');

        $this->assertSame(
            'measurement not found (Connection: analytics, InfluxQL: SELECT * FROM "cpu" WHERE "host" = \'web1\')',
            $exception->getMessage(),
        );
        $this->assertSame('measurement not found', $exception->getError());
        $this->assertSame('analytics', $exception->getConnectionName());
        $this->assertSame('SELECT * FROM "cpu" WHERE "host" = \'web1\'', $exception->getSql());
        $this->assertSame(['web1'], $exception->getBindings());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    #[UnitTest]
    public function testATransportFailureIsChainedWithItsMessageAndCode(): void
    {
        $previous = new ApiException('[400] Error connecting to the API', 400);

        $exception = new QueryException('main', 'SELECT * FROM "cpu"', [], $previous);

        $this->assertSame('[400] Error connecting to the API (Connection: main, InfluxQL: SELECT * FROM "cpu")', $exception->getMessage());
        $this->assertSame('[400] Error connecting to the API', $exception->getError());
        $this->assertSame(400, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
