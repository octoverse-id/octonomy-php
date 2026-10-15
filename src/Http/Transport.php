<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Internal\Json;
use Octoverse\Octonomy\Pagination;
use Octoverse\Octonomy\RequestOptions;

/**
 * Transport is the single HTTP entry point for the SDK. It builds requests under
 * the /api/v1 prefix, attaches the auth/tenant headers, sends them through a
 * Guzzle client, and converts non-2xx responses into typed exceptions.
 *
 * A 2xx is read through exactly one of three methods, chosen by the endpoint's
 * response shape: one() for a single resource, many() for a list, none() for a
 * 204. None of them hands a service the raw body, so a service cannot hydrate
 * the envelope itself and silently get a zero-valued model back.
 *
 * @internal
 */
final class Transport
{
    private readonly ClientInterface $http;
    private readonly string $baseUri;
    private readonly ?string $actorId;

    /** @var array<string, string> */
    private readonly array $defaultHeaders;

    public function __construct(Config $config, string $sdkVersion)
    {
        $this->actorId = $config->actorId;
        $this->baseUri = rtrim($config->baseUrl, '/') . '/api/v1/';
        $this->http = $config->httpClient ?? new GuzzleClient(['timeout' => $config->timeout]);
        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $config->token,
            'X-Tenant-ID' => $config->tenantId,
            'Accept' => 'application/json',
            'User-Agent' => $config->userAgent ?? 'octonomy-php/' . $sdkVersion,
        ];
    }

    /**
     * Call a single-resource endpoint and return the object it wraps.
     *
     * Octonomy wraps every single-resource 2xx as {"data": {...}}
     * (octonomy/core/responses.py upstream); the vendored OpenAPI spec omits the
     * wrapper. The body must carry a non-empty JSON object under "data", or this
     * throws rather than let a model hydrate from nothing.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     *
     * @throws MalformedResponseException when the 2xx body has no "data" object
     */
    public function one(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?RequestOptions $options = null,
    ): array {
        $decoded = Json::decode($this->send($method, $path, $query, $body, $options));

        $data = $decoded instanceof \stdClass ? ($decoded->data ?? null) : null;
        if (!self::isResource($data)) {
            throw self::malformed($method, $path, 'expected a non-empty JSON object under "data"');
        }

        return Json::objectToArray($data);
    }

    /**
     * Call a list endpoint and return its validated {"data": [...],
     * "pagination": {...}} envelope (octonomy/core/pagination.py upstream; the
     * vendored OpenAPI spec shows a bare array instead).
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     *
     * @throws MalformedResponseException when "data" is not a list of non-empty objects or
     *                                    "pagination" is not an object
     */
    public function many(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?RequestOptions $options = null,
    ): Envelope {
        $decoded = Json::decode($this->send($method, $path, $query, $body, $options));

        $rows = $decoded instanceof \stdClass ? ($decoded->data ?? null) : null;
        if (!is_array($rows)) {
            throw self::malformed($method, $path, 'expected a JSON array under "data"');
        }
        $data = [];
        foreach ($rows as $row) {
            if (!self::isResource($row)) {
                throw self::malformed($method, $path, 'expected every "data" item to be a non-empty JSON object');
            }
            $data[] = Json::objectToArray($row);
        }
        $pagination = $decoded->pagination ?? null;
        if (!$pagination instanceof \stdClass) {
            throw self::malformed($method, $path, 'expected a JSON object under "pagination"');
        }

        return new Envelope($data, Pagination::fromArray(Json::objectToArray($pagination)));
    }

    /**
     * Call an endpoint that answers 204 No Content (the delete/deactivate
     * endpoints). Any 2xx body is ignored without being decoded: none is sent by
     * contract, and a stray one must not turn a successful call into an error.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    public function none(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?RequestOptions $options = null,
    ): void {
        $this->send($method, $path, $query, $body, $options);
    }

    /**
     * Send a request and return the raw body of a 2xx response. Non-2xx
     * responses and network failures throw.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    private function send(
        string $method,
        string $path,
        array $query,
        ?array $body,
        ?RequestOptions $options,
    ): string {
        $headers = $this->defaultHeaders;
        $actor = $this->actorId;
        if ($options !== null && $options->actorId !== null) {
            $actor = $options->actorId;
        }
        if ($actor !== null && $actor !== '') {
            $headers['X-Actor-ID'] = $actor;
        }

        $requestOptions = [
            'headers' => $headers,
            'http_errors' => false,
        ];
        if ($query !== []) {
            $requestOptions['query'] = $query;
        }
        if ($body !== null) {
            $requestOptions['json'] = $body;
        }

        $url = $this->baseUri . ltrim($path, '/');

        try {
            $response = $this->http->request($method, $url, $requestOptions);
        } catch (GuzzleException $e) {
            throw new TransportException('octonomy: request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($status, Json::tryDecode($raw));
        }

        return $raw;
    }

    /**
     * Whether a value from Json::decode() can be a resource: a JSON object with
     * at least one field. An empty object is rejected because no Octonomy
     * resource is empty, and accepting one would hydrate the same zero-valued
     * model as a missing key.
     *
     * @phpstan-assert-if-true \stdClass $value
     */
    private static function isResource(mixed $value): bool
    {
        return $value instanceof \stdClass && get_object_vars($value) !== [];
    }

    private static function malformed(string $method, string $path, string $expected): MalformedResponseException
    {
        return new MalformedResponseException(sprintf(
            'octonomy: malformed response from %s /api/v1/%s: %s',
            $method,
            ltrim($path, '/'),
            $expected,
        ));
    }
}
