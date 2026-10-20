<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Internal\Json;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\Pagination;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;

/**
 * Transport is the single HTTP entry point for the SDK. It builds requests under
 * the /api/{version} prefix of the configured api_version, attaches the
 * auth/tenant headers and any per-request namespace pair, sends them through a
 * Guzzle client, and converts non-2xx responses into typed exceptions. A v2-only
 * feature on a v1 client is refused before anything is sent.
 *
 * A 2xx is read through exactly one of three methods, chosen by the endpoint's
 * response shape: one() for a single resource, many() for a list, none() for a
 * 204. None of them hands a service the raw body, so a service cannot hydrate
 * the envelope itself and silently get a zero-valued model back. one() and
 * many() run the service's hydrator themselves, passing it the api_version the
 * field set depends on, so a field the model rejects is reported with the
 * request and the place in the body it came from.
 *
 * @internal
 */
final class Transport
{
    private readonly ClientInterface $http;
    private readonly string $apiVersion;
    private readonly string $baseUri;
    private readonly ?string $actorId;

    /** @var array<string, string> */
    private readonly array $defaultHeaders;

    /**
     * The api_version is read from $config here, once: it is the surface every
     * request calls and the field set every response hydrates, for the life of
     * this Transport.
     */
    public function __construct(Config $config, string $sdkVersion)
    {
        $this->actorId = $config->actorId;
        $this->apiVersion = $config->apiVersion;
        $this->baseUri = rtrim($config->baseUrl, '/') . '/api/' . $this->apiVersion . '/';
        $this->http = $config->httpClient ?? new GuzzleClient(['timeout' => $config->timeout]);
        $this->defaultHeaders = [
            'Authorization' => 'Bearer ' . $config->token,
            'X-Tenant-ID' => $config->tenantId,
            'Accept' => 'application/json',
            'User-Agent' => $config->userAgent ?? 'octonomy-php/' . $sdkVersion,
        ];
    }

    /**
     * The api_version this Transport calls, an ApiVersion constant.
     */
    public function apiVersion(): string
    {
        return $this->apiVersion;
    }

    /**
     * Call a single-resource endpoint and hydrate the object it wraps.
     *
     * Octonomy wraps every single-resource 2xx as {"data": {...}}
     * (octonomy/core/responses.py upstream); the vendored OpenAPI spec omits the
     * wrapper. The body must carry a non-empty JSON object under "data", or this
     * throws rather than let a model hydrate from nothing.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate builds the result from the "data" object's
     *                                                          members (Json::members()) and the api_version
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     *
     * @return T
     *
     * @throws MalformedResponseException when the 2xx body has no "data" object, or $hydrate rejects it
     * @throws ApiException                when the server answers with a non-2xx status
     * @throws TransportException          when no response arrives or a 2xx body is not JSON
     * @throws \LogicException             when a v1 client is given a v2-only feature (nothing is sent)
     */
    public function one(
        string $method,
        string $path,
        \Closure $hydrate,
        array $query = [],
        ?array $body = null,
        ?RequestOptions $options = null,
    ): mixed {
        $decoded = Json::decode($this->send($method, $path, $query, $body, $options));

        $data = $decoded instanceof \stdClass ? ($decoded->data ?? null) : null;
        if (!self::isResource($data)) {
            throw $this->malformed($method, $path, 'expected a non-empty JSON object under "data"');
        }

        return $this->hydrate($method, $path, 'data', $hydrate, Json::members($data));
    }

    /**
     * Call a list endpoint and hydrate its {"data": [...], "pagination": {...}}
     * envelope (octonomy/core/pagination.py upstream; the vendored OpenAPI spec
     * shows a bare array instead). The whole envelope is checked before any row
     * is hydrated.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate builds one result from each "data" item's
     *                                                          members (Json::members()) and the api_version
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     *
     * @return ResultList<T>
     *
     * @throws MalformedResponseException when "data" is not a list of non-empty objects, "pagination"
     *                                    is not an object, or either fails to hydrate
     * @throws ApiException                when the server answers with a non-2xx status
     * @throws TransportException          when no response arrives or a 2xx body is not JSON
     * @throws \LogicException             when a v1 client is given a v2-only feature (nothing is sent)
     */
    public function many(
        string $method,
        string $path,
        \Closure $hydrate,
        array $query = [],
        ?array $body = null,
        ?RequestOptions $options = null,
    ): ResultList {
        $decoded = Json::decode($this->send($method, $path, $query, $body, $options));

        $rows = $decoded instanceof \stdClass ? ($decoded->data ?? null) : null;
        if (!is_array($rows)) {
            throw $this->malformed($method, $path, 'expected a JSON array under "data"');
        }
        foreach ($rows as $row) {
            if (!self::isResource($row)) {
                throw $this->malformed($method, $path, 'expected every "data" item to be a non-empty JSON object');
            }
        }
        $pagination = $decoded->pagination ?? null;
        if (!$pagination instanceof \stdClass) {
            throw $this->malformed($method, $path, 'expected a JSON object under "pagination"');
        }

        $data = [];
        foreach ($rows as $i => $row) {
            $data[] = $this->hydrate($method, $path, "data[{$i}]", $hydrate, Json::members($row));
        }

        return new ResultList(
            $data,
            $this->hydrate($method, $path, 'pagination', Pagination::fromArray(...), Json::members($pagination)),
        );
    }

    /**
     * Call an endpoint that answers 204 No Content (the delete/deactivate
     * endpoints). Any 2xx body is ignored without being decoded: none is sent by
     * contract, and a stray one must not turn a successful call into an error.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     *
     * @throws ApiException       when the server answers with a non-2xx status
     * @throws TransportException when no response arrives
     * @throws \LogicException    when a v1 client is given a v2-only feature (nothing is sent)
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
        $namespace = $options?->namespace;
        $this->refuseV2OnlyFeatures($namespace, $query);

        $headers = $this->defaultHeaders;
        $actor = $this->actorId;
        if ($options !== null && $options->actorId !== null) {
            $actor = $options->actorId;
        }
        if ($actor !== null && $actor !== '') {
            $headers['X-Actor-ID'] = $actor;
        }
        if ($namespace !== null) {
            $headers['X-Namespace-Type'] = $namespace->type;
            $headers['X-Namespace-ID'] = $namespace->id;
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
     * Refuse a v2-only feature on a v1 client, before anything is sent.
     *
     * This is the one place the SDK checks a request against the server's
     * rules, an exemption AGENTS.md records. It is allowed because the answer is
     * knowable from this client's own configuration, never from server state:
     * /api/v1 has no namespace axis. The server rejects namespace headers there
     * with 400 namespace_not_supported, so refusing them here only saves the
     * round trip. It drops include_global there without a word, so refusing it
     * here is the only way the caller learns it did nothing.
     *
     * @param array<string, string> $query
     *
     * @throws \LogicException when this client is v1 and the request carries either feature
     */
    private function refuseV2OnlyFeatures(?NamespaceScope $namespace, array $query): void
    {
        if ($this->apiVersion === ApiVersion::V2) {
            return;
        }
        if ($namespace !== null) {
            throw new \LogicException(sprintf('octonomy: a namespace requires api_version v2; this client is %s', $this->apiVersion));
        }
        if (array_key_exists('include_global', $query)) {
            throw new \LogicException(sprintf('octonomy: include_global requires api_version v2; this client is %s', $this->apiVersion));
        }
    }

    /**
     * Run a hydrator over one object of the body, and place any field it rejects
     * at this request and at $at, the object's path in the body.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate
     * @param array<string, mixed> $object
     *
     * @return T
     */
    private function hydrate(string $method, string $path, string $at, \Closure $hydrate, array $object): mixed
    {
        try {
            return $hydrate($object, $this->apiVersion);
        } catch (MalformedResponseException $e) {
            throw $e->locate($this->endpoint($method, $path), $this->apiVersion, $at);
        }
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

    private function malformed(string $method, string $path, string $expected): MalformedResponseException
    {
        return new MalformedResponseException($expected, null, $this->endpoint($method, $path), $this->apiVersion);
    }

    /**
     * The request as a message names it, such as "GET /api/v1/tags/{id}".
     */
    private function endpoint(string $method, string $path): string
    {
        return sprintf('%s /api/%s/%s', $method, $this->apiVersion, ltrim($path, '/'));
    }
}
