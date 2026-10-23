<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Exception\ApiException;
use Octoverse\Octonomy\Exception\ConfigurationException;
use Octoverse\Octonomy\Exception\MalformedResponseException;
use Octoverse\Octonomy\Exception\TransportException;
use Octoverse\Octonomy\Internal\ErrorMessage;
use Octoverse\Octonomy\Internal\Json;
use Octoverse\Octonomy\Internal\Redaction;
use Octoverse\Octonomy\NamespaceScope;
use Octoverse\Octonomy\Pagination;
use Octoverse\Octonomy\RequestContext;
use Octoverse\Octonomy\RequestOptions;
use Octoverse\Octonomy\ResultList;
use Psr\Http\Message\ResponseInterface;

/**
 * Transport is the single HTTP entry point for the SDK. It builds requests under
 * the /api/{version} prefix of the configured api_version, attaches the
 * auth/tenant headers and any per-request namespace pair, sends them through a
 * Guzzle client, and converts non-2xx responses into typed exceptions. A v2-only
 * feature on a v1 client is refused before anything is sent. Every exception it
 * throws for a request carries that request's RequestContext, and none carries
 * the token, a header or the body (Redaction).
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
    private readonly string $baseUrl;

    /** The scheme, host and port of the base URL, as a message names the server. */
    private readonly string $origin;

    /** Kept apart from the header only so a message can redact it (Redaction::of()). */
    private readonly string $token;
    private readonly ?string $actorId;

    /** @var array<string, string> */
    private readonly array $defaultHeaders;

    /**
     * The api_version is read from $config here, once: it is the surface every
     * request calls and the field set every response hydrates, for the life of
     * this Transport.
     *
     * @throws ConfigurationException when $config carries an unknown api_version
     */
    public function __construct(Config $config, string $sdkVersion)
    {
        // Checked again, not trusted from Config: the PHP 7.4 line loses
        // readonly in the downgrade, so a Config can be changed after its
        // constructor checked it.
        $this->apiVersion = ApiVersion::check($config->apiVersion);
        $this->token = $config->token;
        $this->actorId = $config->actorId;
        $this->baseUrl = rtrim($config->baseUrl, '/');
        $this->origin = self::origin($config->baseUrl);
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
        $context = $this->context($method, $path, $query, $options);
        [$response, $redaction] = $this->send($context, $body, $options);
        $decoded = $this->decode($context, $response, $redaction);

        $data = $decoded instanceof \stdClass ? ($decoded->data ?? null) : null;
        if (!self::isResource($data)) {
            throw new MalformedResponseException('expected a non-empty JSON object under "data"', null, $context);
        }

        return $this->hydrate($context, 'data', $hydrate, Json::members($data), $redaction);
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
        $context = $this->context($method, $path, $query, $options);
        [$response, $redaction] = $this->send($context, $body, $options);
        $decoded = $this->decode($context, $response, $redaction);

        $rows = $decoded instanceof \stdClass ? ($decoded->data ?? null) : null;
        if (!is_array($rows)) {
            throw new MalformedResponseException('expected a JSON array under "data"', null, $context);
        }
        foreach ($rows as $row) {
            if (!self::isResource($row)) {
                throw new MalformedResponseException('expected every "data" item to be a non-empty JSON object', null, $context);
            }
        }
        $pagination = $decoded->pagination ?? null;
        if (!$pagination instanceof \stdClass) {
            throw new MalformedResponseException('expected a JSON object under "pagination"', null, $context);
        }

        $data = [];
        foreach ($rows as $i => $row) {
            $data[] = $this->hydrate($context, "data[{$i}]", $hydrate, Json::members($row), $redaction);
        }

        return new ResultList(
            $data,
            $this->hydrate($context, 'pagination', Pagination::fromArray(...), Json::members($pagination), $redaction),
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
        $this->send($this->context($method, $path, $query, $options), $body, $options);
    }

    /**
     * Describe a request before it is sent, for every exception it may end in.
     * The path is placed under this client's api_version prefix, as sent.
     *
     * The namespace is a copy, and send() builds the headers from it, so the
     * context records what was sent even if the caller's NamespaceScope
     * changes afterwards, as the PHP 7.4 line, which has no readonly, allows.
     *
     * @param array<string, string> $query
     */
    private function context(string $method, string $path, array $query, ?RequestOptions $options): RequestContext
    {
        $namespace = $options?->namespace;

        return new RequestContext(
            $this->apiVersion,
            $method,
            '/api/' . $this->apiVersion . '/' . ltrim($path, '/'),
            $query,
            $namespace === null ? null : clone $namespace,
        );
    }

    /**
     * Send the request $context describes and return the 2xx response, with
     * the redaction every message about it applies: a snapshot of the token,
     * the actor id and the body exactly as they were handed to the HTTP
     * client. A non-2xx response, and anything the client throws, become
     * exceptions carrying $context.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array{ResponseInterface, Redaction}
     */
    private function send(RequestContext $context, ?array $body, ?RequestOptions $options): array
    {
        $namespace = $context->namespace;
        $query = $context->query;
        $this->refuseV2OnlyFeatures($namespace, $query);

        $headers = $this->defaultHeaders;
        $actor = $this->actor($options);
        if ($actor !== null) {
            $headers['X-Actor-ID'] = $actor;
        }
        if ($namespace !== null) {
            $headers['X-Namespace-Type'] = $namespace->type;
            $headers['X-Namespace-ID'] = $namespace->id;
        }
        $encoded = $body === null ? null : $this->encode($context, $body);
        if ($encoded !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        // Checked again here, not trusted from Config, RequestOptions and
        // NamespaceScope: the PHP 7.4 line loses readonly, so a value can be
        // changed after its constructor checked it. Guzzle refuses a value a
        // header cannot carry from frames that hold every header and the body.
        foreach ($headers as $name => $value) {
            if (!Redaction::isHeaderSafe($value)) {
                throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::unsendable("the {$name} value", $name)));
            }
        }

        // Taken from the very bytes and values handed to the client, before it
        // runs: its middleware can change an object the caller still holds.
        // The client's own default X-Actor-ID goes out when this request sends
        // none, so it is taken too.
        $redaction = Redaction::of($this->token, $actor, $encoded, $actor === null ? $this->defaultActorIds() : []);

        $requestOptions = [
            'headers' => $headers,
            'http_errors' => false,
            // The body and the Authorization header are the SDK's alone. An
            // injected client's own body or auth defaults would replace them
            // (Guzzle merges defaults under these options, and drops a null
            // one), and then what is sent would not be what is redacted.
            'body' => $encoded,
            'json' => null,
            'form_params' => null,
            'multipart' => null,
            'auth' => null,
        ];
        if ($query !== []) {
            $requestOptions['query'] = $query;
        }

        try {
            $response = $this->http->request($context->method, $this->baseUrl . $context->path, $requestOptions);
        } catch (\Throwable $e) {
            // Anything the client, a handler or middleware throws, not only a
            // GuzzleException: none may leave with this request's frames
            // unscrubbed. Quoted in the message, not chained as getPrevious():
            // Guzzle's exception holds the request it failed to send,
            // Authorization header and body included (Redaction).
            throw new TransportException(
                ErrorMessage::forClientFailure(
                    $context,
                    $this->origin,
                    $e,
                    $e instanceof RequestException ? $e->getResponse()?->getStatusCode() : null,
                    $redaction,
                ),
                $context,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse(
                $status,
                Json::tryDecode((string) $response->getBody()),
                $context,
                $response->getHeaderLine('Content-Type'),
                $redaction,
            );
        }

        return [$response, $redaction];
    }

    /**
     * The body as JSON, encoded once: these bytes are what is sent and what is
     * redacted, so an object in the body cannot encode one way for each. The
     * same bytes as Guzzle's json option: json_encode()'s default flags, the
     * throw flag aside.
     *
     * @param array<string, mixed> $body
     *
     * @throws \InvalidArgumentException when it does not encode; nothing is sent
     */
    private function encode(RequestContext $context, array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // json_encode() itself refused the body only when its own frame
            // threw: a JsonException can also come from an object's
            // jsonSerialize(), whose frame is then the first. Described from
            // the error code, or named by class; never quoted and never
            // chained, since a message or a frame can hold the body.
            $refused = $e instanceof \JsonException && ($e->getTrace()[0]['function'] ?? null) === 'json_encode';
            throw Redaction::scrub(new \InvalidArgumentException(ErrorMessage::unencodable(
                $context,
                $refused
                    ? ErrorMessage::jsonError($e->getCode())
                    : sprintf('an object in it threw %s from jsonSerialize(), whose message is not shown', $e::class),
                $refused
                    ? 'put only what JSON can encode in it: UTF-8 strings, finite numbers, booleans, null, and arrays'
                    . ' or objects of those.'
                    : 'fix the jsonSerialize() that throws.',
            )));
        }
    }

    /**
     * Decode a 2xx body (Json::decode()), reporting one that is not JSON as a
     * TransportException for the request it answered: a proxy or gateway that
     * sent HTML with a 2xx gave no usable response.
     *
     * @throws TransportException when the body is not valid JSON
     */
    private function decode(RequestContext $context, ResponseInterface $response, Redaction $redaction): mixed
    {
        $raw = (string) $response->getBody();
        try {
            return Json::decode($raw);
        } catch (\JsonException $e) {
            // Its message is PHP's own and is quoted; the exception is not
            // chained, since json_decode()'s frame in its trace holds the raw
            // body, which can echo the request.
            throw new TransportException(
                ErrorMessage::forUndecodable(
                    $context,
                    $response->getStatusCode(),
                    $response->getHeaderLine('Content-Type'),
                    strlen($raw),
                    $e->getMessage(),
                    $redaction,
                ),
                $context,
            );
        }
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
        // Scrubbed like an OctonomyException, since the frames below this one
        // hold the request body and the RequestOptions.
        if ($namespace !== null) {
            throw Redaction::scrub(new \LogicException(sprintf(
                'octonomy: a namespace requires api_version v2; this client is %s, and /api/v1 has no namespaces.'
                . ' Fix: build the Client with \'api_version\' => \'v2\' (Octonomy server 2.0.0 or newer), or leave'
                . ' the namespace out of RequestOptions to use the global partition.',
                $this->apiVersion,
            )));
        }
        if (array_key_exists('include_global', $query)) {
            throw Redaction::scrub(new \LogicException(sprintf(
                'octonomy: include_global requires api_version v2; this client is %s, and /api/v1 would ignore it.'
                . ' Fix: build the Client with \'api_version\' => \'v2\' (Octonomy server 2.0.0 or newer), or leave'
                . ' include_global null.',
                $this->apiVersion,
            )));
        }
    }

    /**
     * Run a hydrator over one object of the body, and place any field it rejects
     * at this request and at $at, the object's path in the body, with any value
     * the report quotes redacted by the request's $redaction.
     *
     * @template T
     *
     * @param \Closure(array<string, mixed>, string): T $hydrate
     * @param array<string, mixed> $object
     *
     * @return T
     */
    private function hydrate(
        RequestContext $context,
        string $at,
        \Closure $hydrate,
        array $object,
        Redaction $redaction,
    ): mixed {
        try {
            return $hydrate($object, $this->apiVersion);
        } catch (MalformedResponseException $e) {
            throw $e->locate($context, $at, $redaction);
        }
    }

    /**
     * The X-Actor-ID values an injected Guzzle client adds by default, which
     * Guzzle sends when the request names none. Read through getConfig(),
     * which Guzzle 7 has and 8 drops; none when the client has no such method.
     *
     * @return list<string>
     */
    private function defaultActorIds(): array
    {
        $headers = self::configOf($this->http, 'headers');
        $ids = [];
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'X-Actor-ID') === 0) {
                foreach (is_array($value) ? $value : [$value] as $id) {
                    if (is_string($id)) {
                        $ids[] = $id;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * A Guzzle client's configuration option, or null when $client has no
     * getConfig(). Typed object, not ClientInterface, because Guzzle 8 drops
     * the method that Guzzle 7's interface declares.
     */
    private static function configOf(object $client, string $option): mixed
    {
        return method_exists($client, 'getConfig') ? $client->getConfig($option) : null;
    }

    /**
     * The X-Actor-ID a request sends: RequestOptions::$actorId when set, else
     * Config::$actorId, and none when the one that applies is empty.
     */
    private function actor(?RequestOptions $options): ?string
    {
        $actor = $options->actorId ?? $this->actorId;

        return $actor === '' ? null : $actor;
    }

    /**
     * The scheme, host and port of $baseUrl, such as "https://octonomy.example.com:8443".
     * Any user name or password in it is left out, so a message can name the
     * server it could not reach.
     */
    private static function origin(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            // Config refuses such a URL. Only the PHP 7.4 line, where Config is
            // mutable, can get here; name the setting rather than echo it.
            return 'base_url';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
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
}
