<?php

declare(strict_types=1);

namespace Ipsocode\InfluxDB\Sql;

use InfluxDB2\ApiException;
use InfluxDB2\DefaultApi;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;

/**
 * The transport of InfluxDB 3's SQL API.
 *
 * InfluxDB 3 answers SQL on `/api/v3/query_sql`, which the upstream client
 * can reach but has no method for. This adds that method, and one for
 * `/ping`, on top of DefaultApi, so the token header, redirect handling,
 * `verifySSL`, `proxy`, `timeout`, `debug` and an injected `httpClient` all
 * apply exactly as they do to writes and InfluxQL queries.
 *
 * One instance holds one HTTP client. Each SqlConnection builds its own, and
 * Hypervel's database pool decides how many of those it keeps.
 */
class SqlApi extends DefaultApi
{
    /**
     * Run one SQL statement against a database and return its rows.
     *
     * The statement travels in a JSON body with the database, and the rows
     * come back as a JSON array of objects. InfluxDB 3 leaves a column out of
     * a row whose value is null, so every row is given every column the
     * others have, null where it was left out; a column that is null in every
     * row cannot be told apart from one never selected, and is not given. An
     * integer too large for PHP comes back as a numeric string.
     *
     * @return list<stdClass>
     *
     * @throws ApiException on a transport failure, a non-2xx status (a
     *                      statement the server refuses is a 400) or a body
     *                      that is not a list of rows, which is also what a
     *                      statement that fails once its rows have started
     *                      streaming leaves
     * @throws JsonException when the statement is not valid UTF-8
     */
    public function query(string $database, string $sql): array
    {
        $request = $this->createRequest(
            'POST',
            '/api/v3/query_sql',
            json_encode(['db' => $database, 'q' => $sql, 'format' => 'json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            [],
        );

        $response = $this->sendRequest($request);

        $body = (string) $response->getBody();

        try {
            $rows = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw $this->unexpectedResponse('The /api/v3/query_sql response is not JSON: ' . $exception->getMessage(), $response, $body);
        }

        if (! is_array($rows) || ! array_is_list($rows) || ! array_all($rows, static fn (mixed $row): bool => is_array($row))) {
            throw $this->unexpectedResponse('The /api/v3/query_sql response is not a list of rows.', $response, $body);
        }

        return $this->fillLeftOutColumns($rows);
    }

    /**
     * Ask the server about itself.
     *
     * InfluxDB 3 answers `GET /ping` with a JSON object that names its
     * `product_name`, `version` and `revision`.
     *
     * @return array<array-key, mixed>
     *
     * @throws ApiException on a transport failure, a non-2xx status or a body that is not a JSON object
     */
    public function ping(): array
    {
        $response = $this->sendRequest($this->createRequest('GET', '/ping', '', ['Accept' => 'application/json'], []));

        $body = (string) $response->getBody();

        try {
            $about = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->unexpectedResponse('The /ping response is not JSON: ' . $exception->getMessage(), $response, $body);
        }

        if (! is_array($about)) {
            throw $this->unexpectedResponse('The /ping response is not a JSON object.', $response, $body);
        }

        return $about;
    }

    /**
     * Give every row the columns the others have, null where the server left one out.
     *
     * A row that already has every column keeps its own order; one that
     * lacks some takes the order in which the rows first named them.
     *
     * @param list<array<array-key, mixed>> $rows
     * @return list<stdClass>
     */
    protected function fillLeftOutColumns(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            $columns += $row;
        }

        $empty = array_fill_keys(array_keys($columns), null);

        return array_map(
            static fn (array $row): stdClass => (object) (count($row) === count($empty) ? $row : array_replace($empty, $row)),
            $rows,
        );
    }

    /**
     * Describe a 2xx response that is not an answer from the endpoint.
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
