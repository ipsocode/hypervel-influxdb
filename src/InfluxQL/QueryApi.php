<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\InfluxQL;

use InfluxDB2\ApiException;
use InfluxDB2\DefaultApi;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * The InfluxQL counterpart of InfluxDB2\QueryApi.
 *
 * The upstream client only speaks Flux (`/api/v2/query`). InfluxQL is served
 * by the 1.x-compatible `/query` endpoint instead, which the upstream
 * transport can reach but has no method for. This adds that method, and one
 * for `/ping`, on top of DefaultApi, so the token header, redirect handling,
 * `verifySSL`, `proxy`, `timeout`, `debug` and an injected `httpClient` all
 * apply exactly as they do to writes and Flux queries.
 *
 * One instance holds one HTTP client, so it is built once per connection and
 * memoised — see InfluxDBManager::influxql().
 */
class QueryApi extends DefaultApi
{
    /**
     * Run one or more InfluxQL statements and return the decoded `results` list.
     *
     * The statement travels in the form body, so its length is not bound by
     * the URL; `db`, `rp` and `epoch` go in the query string, the way the
     * endpoint documents them. Every statement is POSTed: `SELECT ... INTO`
     * and `DELETE` are refused on GET, and a plain SELECT accepts either.
     *
     * InfluxDB 1.x answers every statement with an entry. 2.x answers none
     * for a DELETE or DROP MEASUREMENT that succeeds, and when no statement
     * has an entry it leaves the list out, answering `{}`: that reads as no
     * entries.
     *
     * @param array<string, null|string> $params `db`, `rp` and `epoch`; empty entries are dropped
     * @return list<array<string, mixed>> one entry per statement the server answered with one, in statement order
     *
     * @throws ApiException on a transport failure, a non-2xx status (an
     *                      InfluxQL parse error is a 400) or a body that is not a /query
     *                      response
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
     * InfluxDB answers `GET /ping` with a 204 whose `X-Influxdb-Version`
     * header names the version: `1.8.10` on 1.x, `v2.7.12` on 2.x.
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
