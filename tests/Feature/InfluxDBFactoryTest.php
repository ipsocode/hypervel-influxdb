<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Feature;

use InfluxDB2\Client;
use InvalidArgumentException;
use Ipsocode\InfluxDB\InfluxDBFactory;
use Ipsocode\InfluxDB\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InfluxDBFactoryTest extends TestCase
{
    #[DataProvider('requiredKeys')]
    public function testMakeThrowsWhenARequiredKeyIsMissing(string $missingKey): void
    {
        $config = [
            'name' => 'main',
            'url' => 'http://localhost:8086',
            'token' => 'my-token',
            'bucket' => 'my-bucket',
            'org' => 'my-org',
        ];

        unset($config[$missingKey]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("InfluxDB connection [main] is missing the required [{$missingKey}] config key.");

        (new InfluxDBFactory)->make($config);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function requiredKeys(): array
    {
        return [
            'url' => ['url'],
            'token' => ['token'],
            'bucket' => ['bucket'],
            'org' => ['org'],
        ];
    }

    public function testMakeReturnsAClientConfiguredFromTheGivenOptions(): void
    {
        $client = (new InfluxDBFactory)->make([
            'url' => 'http://localhost:8086',
            'token' => 'my-token',
            'bucket' => 'my-bucket',
            'org' => 'my-org',
            'verifySSL' => true,
            'precision' => 'ms',
            'debug' => true,
        ]);

        $this->assertInstanceOf(Client::class, $client);

        $options = $this->getClientOptions($client);

        $this->assertSame('http://localhost:8086', $options['url']);
        $this->assertSame('my-token', $options['token']);
        $this->assertSame('my-bucket', $options['bucket']);
        $this->assertSame('my-org', $options['org']);
        $this->assertTrue($options['verifySSL']);
        $this->assertSame('ms', $options['precision']);
        $this->assertTrue($options['debug']);
    }

    public function testMakeAppliesDefaultsForOptionalOptions(): void
    {
        $client = (new InfluxDBFactory)->make([
            'url' => 'http://localhost:8086',
            'token' => 'my-token',
            'bucket' => 'my-bucket',
            'org' => 'my-org',
        ]);

        $options = $this->getClientOptions($client);

        $this->assertTrue($options['verifySSL']);
        $this->assertSame('ns', $options['precision']);
        $this->assertFalse($options['debug']);
    }

    public function testMakeHonoursAnExplicitFalseVerifySSL(): void
    {
        $client = (new InfluxDBFactory)->make([
            'url' => 'http://localhost:8086',
            'token' => 'my-token',
            'bucket' => 'my-bucket',
            'org' => 'my-org',
            'verifySSL' => false,
        ]);

        $this->assertFalse($this->getClientOptions($client)['verifySSL']);
    }

    public function testMakeForwardsArbitraryConfigKeysToTheClient(): void
    {
        $client = (new InfluxDBFactory)->make([
            'url' => 'http://localhost:8086',
            'token' => 'my-token',
            'bucket' => 'my-bucket',
            'org' => 'my-org',
            'timeout' => 5,
            'proxy' => 'http://proxy.localhost:8080',
            'tags' => ['host' => 'web1'],
            'write' => ['writeType' => 'batching', 'batchSize' => 1000],
        ]);

        $options = $this->getClientOptions($client);

        $this->assertSame(5, $options['timeout']);
        $this->assertSame('http://proxy.localhost:8080', $options['proxy']);
        $this->assertSame(['host' => 'web1'], $options['tags']);
        $this->assertSame(['writeType' => 'batching', 'batchSize' => 1000], $options['write']);
    }

    public function testMakeStripsTheConnectionNameFromTheClientOptions(): void
    {
        $client = (new InfluxDBFactory)->make([
            'name' => 'main',
            'url' => 'http://localhost:8086',
            'token' => 'my-token',
            'bucket' => 'my-bucket',
            'org' => 'my-org',
        ]);

        $this->assertArrayNotHasKey('name', $this->getClientOptions($client));
    }

    public function testMakeStripsTheVersionAndTheInfluxqlBlockFromTheClientOptions(): void
    {
        $client = (new InfluxDBFactory)->make([
            'version' => 'v2',
            'url' => 'http://localhost:8086',
            'token' => 'my-token',
            'bucket' => 'my-bucket',
            'org' => 'my-org',
            'influxql' => ['database' => 'metrics'],
        ]);

        $this->assertArrayNotHasKey('version', $this->getClientOptions($client));
        $this->assertArrayNotHasKey('influxql', $this->getClientOptions($client));
        $this->assertSame('my-bucket', $this->getClientOptions($client)['bucket']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getClientOptions(Client $client): array
    {
        return $client->options;
    }
}
