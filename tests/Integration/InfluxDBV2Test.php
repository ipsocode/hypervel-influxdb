<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use Hypervel\Support\Facades\DB;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use Ipsocode\InfluxDB\InfluxDBManager;
use Ipsocode\InfluxDB\InfluxQL\QueryException;
use Ipsocode\InfluxDB\InfluxQL\V2Connection;
use Ipsocode\InfluxDB\InfluxQL\Version;
use Ipsocode\InfluxDB\Write\BatchingWriter;
use RuntimeException;

/**
 * The package against a real InfluxDB 2.x: `INFLUXDB_V2_URL` names the server.
 *
 * InfluxQL reaches 2.x through the `/query` v1 compatibility API, which reads the test's
 * bucket as a database through its virtual DBRP mapping.
 *
 * @see docs/influxql.md
 */
class InfluxDBV2Test extends V2IntegrationTestCase
{
    public function testTheServerNamesAVersionTwoVersionOnPing(): void
    {
        $connection = $this->manager()->influxql('integration');

        $this->assertInstanceOf(V2Connection::class, $connection);
        $this->assertSame(Version::V2, $connection->getVersion());
        $this->assertMatchesRegularExpression('/^v2\.\d+\.\d+/', (string) $connection->getServerVersion());
    }

    public function testPointsReadBackTheSameThroughTheBuilderAndTheDatabaseDriver(): void
    {
        $this->manager()->writeApi('integration')->write(
            Point::measurement('cpu')
                ->addTag('host', 'a')
                ->addTag('region', 'eu')
                ->addField('load', 1.5)
                ->addField('count', 3)
                ->addField('up', true)
                ->addField('note', 'hello world')
                ->time(1_700_000_000, WritePrecision::S),
            WritePrecision::S,
        );

        $expected = [
            'time' => 1_700_000_000,
            'count' => 3,
            'host' => 'a',
            'load' => 1.5,
            'note' => 'hello world',
            'region' => 'eu',
            'up' => true,
        ];

        $rows = $this->manager()->table('cpu', 'integration')->get();
        $driverRows = DB::connection('influxql')->table('cpu')->get();

        $this->assertCount(1, $rows);
        $this->assertCount(1, $driverRows);
        $this->assertSame('2023-11-14T22:13:20Z', $rows[0]->time);
        $this->assertEquals($expected, $this->withoutTime($rows[0], $expected));
        $this->assertSame($rows[0]->time, $driverRows[0]->time);
        $this->assertEquals($expected, $this->withoutTime($driverRows[0], $expected));
        $this->assertIsFloat($rows[0]->load);
        $this->assertIsInt($rows[0]->count);
        $this->assertIsBool($rows[0]->up);
    }

    public function testABatchingConnectionsPointsArriveOnceItFlushesOnItsInterval(): void
    {
        $writeApi = $this->manager()->writeApi('integration-batch');

        $this->assertInstanceOf(BatchingWriter::class, $writeApi);

        $writeApi->write('batch value=1i 1700000000000000000');

        $this->eventually(fn (): bool => $this->manager()->table('batch', 'integration')->count() === 1);

        $this->assertSame(0, $writeApi->getPendingPoints());
        $this->assertSame(
            [1],
            DB::connection('influxql')->table('batch')->pluck('value')->all(),
        );
    }

    public function testTheGrammarRefusesSelectIntoAsTheServerDoes(): void
    {
        $this->assertFalse(Version::V2->supportsSelectInto());

        $this->manager()->writeApi('integration')->writeRaw('raw v=1i 1700000000000000000', WritePrecision::NS);

        try {
            $this->manager()->table('raw', 'integration')->select('v')->into('copy')->get();

            $this->fail('The grammar compiled a SELECT ... INTO for InfluxDB 2.x.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('does not support SELECT ... INTO', $exception->getMessage());
        }

        try {
            $this->manager()->influxql('integration')->select('SELECT "v" INTO "copy" FROM "raw"');

            $this->fail('The server accepted a SELECT ... INTO.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('not implemented: SELECT INTO', $exception->getError());
        }
    }

    public function testTheGrammarAllowsSlimitAndSoffsetAsTheServerDoes(): void
    {
        $this->assertTrue(Version::V2->supportsSeriesLimits());

        $this->manager()->writeApi('integration')->writeRaw(implode("\n", [
            'net,host=a v=1i 1700000000000000000',
            'net,host=b v=2i 1700000000000000000',
            'net,host=c v=3i 1700000000000000000',
        ]), WritePrecision::NS);

        $query = fn () => $this->manager()->table('net', 'integration')->select('v')->groupBy('host');

        $this->assertCount(1, $query()->slimit(1)->get());
        $this->assertSame(['c'], $query()->slimit(1)->soffset(2)->get()->pluck('host')->all());
        $this->assertSame('slimit 1 soffset 2', substr($query()->slimit(1)->soffset(2)->toRawSql(), -18));
    }

    public function testTheGrammarAllowsDeleteAsTheServerDoes(): void
    {
        $this->assertTrue(Version::V2->supportsDelete());

        $this->manager()->writeApi('integration')->writeRaw(implode("\n", [
            'disk,host=a used=1i 1700000000000000000',
            'disk,host=a used=2i 1700000001000000000',
            'disk,host=b used=3i 1700000002000000000',
        ]), WritePrecision::NS);

        $this->assertTrue($this->manager()->table('disk', 'integration')->where('host', 'a')->delete());

        $this->assertSame([3], $this->manager()->table('disk', 'integration')->pluck('used')->all());
    }

    public function testTheGrammarRefusesADeleteOnARetentionPolicyAsTheServerIgnoresIt(): void
    {
        $this->assertTrue(Version::V2->deletesFromDefaultRetentionPolicyOnly());

        $config = $this->app->get('config');
        $config->set('influxdb.connections.integration-rp', $config->get('influxdb.connections.integration') + [
            'influxql' => ['retentionPolicy' => 'weekly'],
        ]);

        $this->manager()->writeApi('integration')->writeRaw('mem,host=a used=1i 1700000000000000000', WritePrecision::NS);

        try {
            $this->manager()->table('mem', 'integration-rp')->where('host', 'a')->delete();

            $this->fail('The grammar compiled a DELETE on a retention policy for InfluxDB 2.x.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('deletes only from the database\'s default retention policy', $exception->getMessage());
        }

        $this->assertSame(1, $this->manager()->table('mem', 'integration')->count());

        // The server answers a DELETE on a retention policy that does not exist, and deletes from the default one.
        $this->assertTrue($this->manager()->influxql('integration-rp')->statement('DELETE FROM "mem" WHERE "host" = \'a\''));
        $this->assertSame(0, $this->manager()->table('mem', 'integration')->count());
    }

    private function manager(): InfluxDBManager
    {
        return $this->app->get(InfluxDBManager::class);
    }

    /**
     * The row as an array, keyed and ordered as the expectation is, with its time as the expectation has it.
     *
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function withoutTime(object $row, array $expected): array
    {
        $values = (array) $row;
        $values['time'] = $expected['time'];

        ksort($values);

        return $values;
    }
}
