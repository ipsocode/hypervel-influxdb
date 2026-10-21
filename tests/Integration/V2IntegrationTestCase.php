<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Tests\Integration;

use InfluxDB2\Client;
use InfluxDB2\Model\PostBucketRequest;
use InfluxDB2\Service\BucketsService;
use InfluxDB2\Service\OrganizationsService;
use RuntimeException;

/**
 * A test against a real InfluxDB 2.x, whose URL, token and organization the environment names.
 *
 * InfluxDB 2.x has no `CREATE DATABASE`, so the test's database is a bucket made through
 * the API. A bucket named without a slash has a virtual DBRP mapping, the database of that
 * name on its default retention policy, so InfluxQL reaches it with no mapping of its own.
 */
abstract class V2IntegrationTestCase extends IntegrationTestCase
{
    protected const string URL_VARIABLE = 'INFLUXDB_V2_URL';

    protected const string VERSION = 'v2';

    private ?string $bucketId = null;

    /**
     * @return array<string, mixed>
     */
    protected function connectionConfig(string $url): array
    {
        return [
            'version' => static::VERSION,
            'url' => $url,
            'token' => $this->token(),
            'bucket' => $this->database,
            'org' => $this->organization(),
            'precision' => 'ns',
            'logFile' => '/dev/null',
        ];
    }

    protected function createDatabase(): void
    {
        $client = $this->apiClient();

        $organizations = $client->createService(OrganizationsService::class)->getOrgs(org: $this->organization())->getOrgs();

        if ($organizations === null || $organizations === []) {
            throw new RuntimeException("The organization [{$this->organization()}] does not exist on the server.");
        }

        $bucket = $client->createService(BucketsService::class)->postBuckets(new PostBucketRequest([
            'org_id' => $organizations[0]->getId(),
            'name' => $this->database,
        ]));

        $this->bucketId = $bucket->getId();
    }

    protected function dropDatabase(): void
    {
        if ($this->bucketId === null) {
            return;
        }

        $this->apiClient()->createService(BucketsService::class)->deleteBucketsID($this->bucketId);
    }

    /**
     * The token the server was started with, or `it-token`, the one CONTRIBUTING.md starts it with.
     */
    protected function token(): string
    {
        return $this->environment('INFLUXDB_V2_TOKEN', 'it-token');
    }

    /**
     * The organization the server was started with, or `it-org`, the one CONTRIBUTING.md starts it with.
     */
    protected function organization(): string
    {
        return $this->environment('INFLUXDB_V2_ORG', 'it-org');
    }

    /**
     * A client of its own for the administrative calls, which run outside the test's coroutine.
     */
    private function apiClient(): Client
    {
        return new Client([
            'url' => (string) $this->serverUrl(),
            'token' => $this->token(),
            'org' => $this->organization(),
            'bucket' => $this->database,
        ]);
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
