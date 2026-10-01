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
 * The InfluxDB client's DefaultApi, plus the `/api/v3/query_sql` and `/ping`
 * methods it lacks, so the token, `verifySSL`, `proxy`, `timeout` and
 * `httpClient` apply as they do to writes. Each SqlConnection has its own.
 *
 * @see docs/sql.md#what-to-expect
 */
class SqlApi extends DefaultApi
{
    /**
     * Run one SQL statement against a database and return its rows.
     *
     * InfluxDB 3 leaves a null column out of a row, so each row is given the
     * columns the others have; an integer too large for PHP is a numeric string.
     *
     * @return list<stdClass>
     *
     * @throws ApiException on a transport failure, a non-2xx status or a body that is not a list of rows
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

        // A statement that fails once its rows have started streaming still
        // answers 2xx, with a body that is not a list of rows.
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
     * InfluxDB 3 answers with a JSON object naming its `product_name`, `version` and `revision`.
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
