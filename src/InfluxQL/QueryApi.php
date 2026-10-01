<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use InfluxDB2\ApiException;
use InfluxDB2\DefaultApi;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * The InfluxQL counterpart of InfluxDB2\QueryApi, which speaks only Flux.
 *
 * It adds `/query` and `/ping` to DefaultApi, so the client's `token`, `allow_redirects`,
 * `verifySSL`, `proxy`, `timeout`, `debug` and `httpClient` options apply. Each holds
 * one HTTP client: one per connection, which InfluxDBManager::influxql() memoises.
 *
 * @see docs/influxql.md#running-statements
 */
class QueryApi extends DefaultApi
{
    /**
     * Run one or more InfluxQL statements and return the decoded `results` list.
     *
     * Every statement is POSTed, since `SELECT ... INTO` and `DELETE` are refused
     * on GET; InfluxDB 2.x answers `{}` when no statement has a result.
     *
     * @param array<string, null|string> $params `db`, `rp` and `epoch`; empty entries are dropped
     * @return list<array<string, mixed>> one entry per statement the server answered with one, in statement order
     *
     * @throws ApiException on a transport failure, a non-2xx status or a body that is not a /query response
     */
    public function query(string $query, array $params = []): array
    {
        $request = $this->createRequest(
            'POST',
            '/query',
            http_build_query(['q' => $query], '', '&', PHP_QUERY_RFC3986),
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            array_filter($params, static fn (?string $value): bool => $value !== null && $value !== ''),
        );

        $response = $this->sendRequest($request);

        $body = (string) $response->getBody();

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->unexpectedResponse('The /query response is not JSON: ' . $exception->getMessage(), $response, $body);
        }

        if ($decoded === []) {
            return [];
        }

        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            throw $this->unexpectedResponse('The /query response carries no "results" list.', $response, $body);
        }

        return array_values($decoded['results']);
    }

    /**
     * Ask the server which InfluxDB version it runs.
     *
     * The `X-Influxdb-Version` header of `/ping` reads `1.8.10` on 1.x and `v2.7.12` on 2.x.
     *
     * @param array<string, string> $headers extra request headers, such as `Connection: close` for a ping whose connection must not be kept open
     * @return null|string the header's value, or null when the server sends none
     *
     * @throws ApiException on a transport failure or a non-2xx status
     */
    public function ping(array $headers = []): ?string
    {
        $response = $this->sendRequest($this->createRequest('GET', '/ping', '', $headers, []));

        $version = $response->getHeaderLine('X-Influxdb-Version');

        return $version === '' ? null : $version;
    }

    /**
     * Describe a 2xx response that is not a /query response.
     */
    protected function unexpectedResponse(string $message, ResponseInterface $response, string $body): ApiException
    {
        return new ApiException(
            $message,
            $response->getStatusCode(),
            array_map(static fn (array $values): string => implode(', ', $values), $response->getHeaders()),
            $body,
        );
    }
}
