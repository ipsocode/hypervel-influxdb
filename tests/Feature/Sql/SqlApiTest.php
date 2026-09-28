<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature\Sql;

use GuzzleHttp\Psr7\Response;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use InfluxDB2\ApiException;
use Ipsocode\InfluxDB\Sql\SqlApi;
use Ipsocode\InfluxDB\Tests\Concerns\MocksInfluxQL;
use Ipsocode\InfluxDB\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class SqlApiTest extends TestCase
{
    use MocksInfluxQL;

    #[UnitTest]
    public function testItPostsTheStatementAndTheDatabaseAsJson(): void
    {
        $api = $this->api([self::rows([['host' => 'web1', 'usage_user' => 0.64]])]);

        $rows = $api->query('telegraf/autogen', 'select "host", "usage_user" from "cpu" where "host" = \'web1\'');

        $request = $this->lastRequest();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v3/query_sql', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame(
            '{"db":"telegraf/autogen","q":"select \"host\", \"usage_user\" from \"cpu\" where \"host\" = \'web1\'","format":"json"}',
            (string) $request->getBody(),
        );
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('Token main-token', $request->getHeaderLine('Authorization'));

        $this->assertContainsOnlyInstancesOf(stdClass::class, $rows);
        $this->assertSame([['host' => 'web1', 'usage_user' => 0.64]], $this->arrays($rows));
    }

    #[UnitTest]
    public function testAStatementIsSentAsWrittenWhateverItsCharacters(): void
    {
        $api = $this->api([self::rows([])]);

        $api->query('métriques', "select 'café/😀' as \"x\"");

        $this->assertSame(['db' => 'métriques', 'q' => "select 'café/😀' as \"x\"", 'format' => 'json'], json_decode((string) $this->lastRequest()->getBody(), true));
    }

    #[UnitTest]
    public function testAStatementThatIsNotUtf8IsRefusedBeforeItIsSent(): void
    {
        $api = $this->api([self::rows([])]);

        try {
            $api->query('telegraf', "select '\xB1\x31'");

            $this->fail('No exception was thrown.');
        } catch (JsonException) {
            $this->assertSame([], $this->history);
        }
    }

    #[UnitTest]
    public function testAnEmptyResultIsNoRows(): void
    {
        $this->assertSame([], $this->api([self::rows([])])->query('telegraf', 'select * from "cpu"'));
    }

    /**
     * InfluxDB 3 leaves a column out of a row whose value is null.
     */
    #[UnitTest]
    public function testEveryRowIsGivenTheColumnsTheServerLeftOutAsNull(): void
    {
        $api = $this->api([new Response(200, ['Content-Type' => 'application/json'], '[{"host":"web1","note":"x","time":"2024-01-01T00:00:00"},{"host":"web2","time":"2024-01-01T00:01:00"},{},{"region":"eu","time":"2024-01-01T00:02:00"}]')]);

        $this->assertSame([
            ['host' => 'web1', 'note' => 'x', 'time' => '2024-01-01T00:00:00', 'region' => null],
            ['host' => 'web2', 'note' => null, 'time' => '2024-01-01T00:01:00', 'region' => null],
            ['host' => null, 'note' => null, 'time' => null, 'region' => null],
            ['host' => null, 'note' => null, 'time' => '2024-01-01T00:02:00', 'region' => 'eu'],
        ], $this->arrays($api->query('telegraf', 'select * from "cpu"')));
    }

    #[UnitTest]
    public function testARowWithEveryColumnKeepsItsOwnOrder(): void
    {
        $api = $this->api([new Response(200, [], '[{"b":1,"a":2},{"a":3,"b":4}]')]);

        $this->assertSame([['b' => 1, 'a' => 2], ['a' => 3, 'b' => 4]], $this->arrays($api->query('telegraf', 'select * from "t"')));
    }

    #[UnitTest]
    public function testValuesKeepTheirJsonTypesAndIntegersTooLargeForPhpComeBackAsStrings(): void
    {
        $api = $this->api([new Response(200, [], '[{"i":9223372036854775807,"u":18446744073709551615,"f":1.0,"b":false,"s":"1","n":null,"0":"zero"}]')]);

        $row = $api->query('telegraf', 'select * from "t"')[0];

        $this->assertSame(PHP_INT_MAX, $row->i);
        $this->assertSame('18446744073709551615', $row->u);
        $this->assertSame(1.0, $row->f);
        $this->assertFalse($row->b);
        $this->assertSame('1', $row->s);
        $this->assertNull($row->n);
        $this->assertSame('zero', $row->{'0'});
    }

    #[UnitTest]
    #[DataProvider('bodiesThatAreNotJson')]
    public function testABodyThatIsNotJsonIsAnApiException(string $body): void
    {
        $api = $this->api([new Response(200, ['Content-Type' => ['application/json', 'charset=utf-8']], $body)]);

        try {
            $api->query('telegraf', 'select * from "cpu"');

            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertStringStartsWith('The /api/v3/query_sql response is not JSON: ', $exception->getMessage());
            $this->assertSame(200, $exception->getCode());
            $this->assertSame($body, $exception->getResponseBody());
            $this->assertSame('application/json, charset=utf-8', $exception->getResponseHeaders()['Content-Type']);
        }
    }

    /**
     * InfluxDB 3 streams the rows after a 200, so a statement that fails
     * once they have started leaves the array unfinished.
     *
     * @return array<string, array{string}>
     */
    public static function bodiesThatAreNotJson(): array
    {
        return [
            'text' => ['not json'],
            'rows cut short' => ['[{"host":"web1"},{"host":'],
            'nothing' => [''],
        ];
    }

    #[UnitTest]
    #[DataProvider('bodiesThatAreNotRows')]
    public function testJsonThatIsNotAListOfRowsIsAnApiException(string $body): void
    {
        $api = $this->api([new Response(200, [], $body)]);

        try {
            $api->query('telegraf', 'select * from "cpu"');

            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('The /api/v3/query_sql response is not a list of rows.', $exception->getMessage());
            $this->assertSame($body, $exception->getResponseBody());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatAreNotRows(): array
    {
        return [
            'an object' => ['{"error":"unexpected"}'],
            'a scalar' => ['"rows"'],
            'a list of scalars' => ['[1, 2]'],
            'a row among scalars' => ['[{"host":"web1"}, "web2"]'],
        ];
    }

    #[UnitTest]
    public function testAnErrorStatusIsTheUpstreamApiExceptionCarryingTheServersMessage(): void
    {
        $api = $this->api([new Response(400, [], 'SQL error: ParserError("Expected: an SQL statement, found: SELEC at Line: 1, Column: 1")')]);

        try {
            $api->query('telegraf', 'SELEC 1');

            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertSame(400, $exception->getCode());
            $this->assertStringContainsString('SQL error: ParserError("Expected: an SQL statement, found: SELEC', $exception->getMessage());
        }
    }

    #[UnitTest]
    public function testAJsonErrorIsReadForItsMessage(): void
    {
        $api = $this->api([new Response(404, ['Content-Type' => 'application/json'], '{"error":"query error: database not found: missing"}')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessageIsOrContains('query error: database not found: missing');

        $api->query('missing', 'select 1');
    }

    #[UnitTest]
    public function testPingAsksTheServerAboutItself(): void
    {
        $api = $this->api([new Response(200, ['Content-Type' => 'application/json', 'X-Influxdb-Version' => '3.11.5'], '{"product_name":"InfluxDB 3 Core","version":"3.11.5","revision":"f083f73c92"}')]);

        $this->assertSame(['product_name' => 'InfluxDB 3 Core', 'version' => '3.11.5', 'revision' => 'f083f73c92'], $api->ping());

        $request = $this->lastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/ping', $request->getUri()->getPath());
        $this->assertSame('', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('Token main-token', $request->getHeaderLine('Authorization'));
    }

    /**
     * InfluxDB 1.x and 2.x answer /ping with an empty 204.
     */
    #[UnitTest]
    public function testAPingThatIsNotJsonIsAnApiException(): void
    {
        $api = $this->api([self::pingResponse('v2.7.12')]);

        try {
            $api->ping();

            $this->fail('No exception was thrown.');
        } catch (ApiException $exception) {
            $this->assertStringStartsWith('The /ping response is not JSON: ', $exception->getMessage());
            $this->assertSame(204, $exception->getCode());
            $this->assertSame('v2.7.12', $exception->getResponseHeaders()['X-Influxdb-Version']);
        }
    }

    #[UnitTest]
    public function testAPingThatIsNotAJsonObjectIsAnApiException(): void
    {
        $api = $this->api([new Response(200, [], '"3.11.5"')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageIs('The /ping response is not a JSON object.');

        $api->ping();
    }

    #[UnitTest]
    public function testAPingRefusedWithAnErrorStatusIsTheUpstreamApiException(): void
    {
        $api = $this->api([new Response(401, ['Content-Type' => 'application/json'], '{"error": "the request was not authenticated"}')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessageIsOrContains('the request was not authenticated');

        $api->ping();
    }

    /**
     * @param list<Response> $responses
     */
    private function api(array $responses): SqlApi
    {
        return new SqlApi($this->client($responses)->options);
    }

    /**
     * A 200 response carrying the given rows, as InfluxDB 3 sends them.
     *
     * @param list<array<string, mixed>> $rows
     */
    private static function rows(array $rows): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * The rows as arrays, to compare their keys, order and values at once.
     *
     * @param list<stdClass> $rows
     * @return list<array<string, mixed>>
     */
    private function arrays(array $rows): array
    {
        return array_map(static fn (stdClass $row): array => (array) $row, $rows);
    }
}
