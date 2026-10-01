<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\InfluxQL;

use GuzzleHttp\Psr7\Response;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InfluxDB2\ApiException;
use Ipsocode\InfluxDB\InfluxQL\QueryApi;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\TestCase;

class QueryApiTest extends TestCase
{
    use MocksInfluxQL;

    #[UnitTest]
    public function testItPostsTheStatementAsAFormBodyWithTheParametersInTheQueryString(): void
    {
        $api = $this->api([self::seriesResponse([self::series('cpu', ['time', 'value'], [['t', 1]])])]);

        $results = $api->query('SELECT * FROM "cpu"', ['db' => 'main-bucket', 'rp' => 'autogen', 'epoch' => 'ms']);

        $request = $this->lastRequest();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/query', $request->getUri()->getPath());
        $this->assertSame('db=main-bucket&rp=autogen&epoch=ms', $request->getUri()->getQuery());
        $this->assertSame('q=SELECT%20%2A%20FROM%20%22cpu%22', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('Token main-token', $request->getHeaderLine('Authorization'));

        $this->assertSame([['statement_id' => 0, 'series' => [self::series('cpu', ['time', 'value'], [['t', 1]])]]], $results);
    }

    #[UnitTest]
    public function testEmptyParametersAreLeftOutOfTheQueryString(): void
    {
        $api = $this->api([self::emptyResponse()]);

        $api->query('SHOW MEASUREMENTS', ['db' => 'main-bucket', 'rp' => null, 'epoch' => '']);

        $this->assertSame('db=main-bucket', $this->lastRequest()->getUri()->getQuery());
    }

    #[UnitTest]
    public function testTheResultsListIsReindexed(): void
    {
        $api = $this->api([new Response(200, [], '{"results":{"a":{"statement_id":0},"b":{"statement_id":1}}}')]);

        $this->assertSame([['statement_id' => 0], ['statement_id' => 1]], $api->query('SELECT 1; SELECT 2'));
    }

    #[UnitTest]
    public function testAnEmptyObjectIsAnAnswerWithNoResults(): void
    {
        $api = $this->api([self::noResultsResponse()]);

        $this->assertSame([], $api->query('DELETE FROM "cpu"'));
    }

    #[UnitTest]
    public function testAResponseThatIsNotJsonIsAnApiException(): void
    {
        $api = $this->api([new Response(200, ['X-Influxdb-Version' => ['2.7', 'oss']], 'not json')]);

        try {
            $api->query('SELECT * FROM "cpu"');

            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertStringStartsWith('The /query response is not JSON: ', $exception->getMessage());
            $this->assertSame(200, $exception->getCode());
            $this->assertSame('not json', $exception->getResponseBody());
            $this->assertSame('2.7, oss', $exception->getResponseHeaders()['X-Influxdb-Version']);
        }
    }

    #[UnitTest]
    public function testAResponseWithoutAResultsListIsAnApiException(): void
    {
        $api = $this->api([new Response(200, [], '{"error":"unexpected"}')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The /query response carries no "results" list.');

        $api->query('SELECT * FROM "cpu"');
    }

    #[UnitTest]
    public function testAJsonScalarIsAResponseWithoutAResultsList(): void
    {
        $api = $this->api([new Response(200, [], '"results"')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The /query response carries no "results" list.');

        $api->query('SELECT * FROM "cpu"');
    }

    #[UnitTest]
    public function testAnErrorStatusIsTheClientsApiException(): void
    {
        $api = $this->api([new Response(400, [], '{"error":"error parsing query: found EOF"}')]);

        try {
            $api->query('SELECT');

            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(400, $exception->getCode());
            $this->assertStringContainsString('error parsing query: found EOF', $exception->getMessage());
        }
    }

    #[UnitTest]
    public function testPingReadsTheVersionTheServerNames(): void
    {
        $api = $this->api([self::pingResponse('1.8.10')]);

        $this->assertSame('1.8.10', $api->ping());

        $request = $this->lastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/ping', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('Token main-token', $request->getHeaderLine('Authorization'));
    }

    #[UnitTest]
    public function testPingSendsTheHeadersItIsGiven(): void
    {
        $api = $this->api([self::pingResponse('v2.7.12'), self::pingResponse('v2.7.12')]);

        $api->ping();

        $this->assertSame('', $this->lastRequest()->getHeaderLine('Connection'));

        $this->assertSame('v2.7.12', $api->ping(['Connection' => 'close']));
        $this->assertSame('close', $this->lastRequest()->getHeaderLine('Connection'));
    }

    /**
     * InfluxDB 3 answers /ping with a 200 and a JSON body, and names its version in the header too.
     */
    #[UnitTest]
    public function testPingReadsTheVersionInfluxdb3NamesInItsHeader(): void
    {
        $api = $this->api([new Response(200, ['Content-Type' => 'application/json', 'X-Influxdb-Version' => '3.11.5', 'X-Influxdb-Build' => 'Core'], '{"product_name":"InfluxDB 3 Core","version":"3.11.5","revision":"f083f73c92"}')]);

        $this->assertSame('3.11.5', $api->ping());
    }

    #[UnitTest]
    public function testPingIsNullWhenTheServerNamesNoVersion(): void
    {
        $this->assertNull($this->api([self::pingResponse(null)])->ping());
    }

    #[UnitTest]
    public function testPingThrowsTheClientsApiExceptionOnAnErrorStatus(): void
    {
        $api = $this->api([new Response(503, [], 'unavailable')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(503);

        $api->ping();
    }

    /**
     * @param list<Response> $responses
     */
    private function api(array $responses): QueryApi
    {
        return new QueryApi($this->client($responses)->options);
    }
}
