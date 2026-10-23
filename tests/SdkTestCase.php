<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Octoverse\Octonomy\ApiVersion;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
use Octoverse\Octonomy\Tests\Fixture\SyntheticNegative;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * SdkTestCase wires a Client to a Guzzle MockHandler so tests can queue responses
 * captured from a real server and inspect the request the SDK actually sent.
 */
abstract class SdkTestCase extends TestCase
{
    protected MockHandler $mock;

    /**
     * Build a Client whose requests are answered, in order, by $responses.
     *
     * Responses enter only as captured fixtures or labelled synthetic negatives,
     * never as a hand-built Response, and the HTTP client cannot be swapped
     * through $config: those are the two ways a hand-written body would reach
     * the SDK (AGENTS.md).
     *
     * @param list<CapturedFixture|SyntheticNegative> $responses
     * @param array<string, mixed> $config
     */
    protected function clientWith(array $responses, array $config = []): Client
    {
        if (array_key_exists('http_client', $config)) {
            throw new \LogicException('clientWith() owns the HTTP client; pass captured() or syntheticNegative() responses instead');
        }

        $client = new Client(array_merge([
            'base_url' => 'https://octonomy.test',
            'token' => 'test-token',
            'tenant_id' => 'tenant-1',
            'http_client' => $this->mockedHttpClient($responses),
        ], $config));
        self::refuseCrossVersionReplay($client->apiVersion(), $responses);

        return $client;
    }

    /**
     * Build a Client whose first request gets no response at all: the
     * connection is refused, as a host with nothing listening refuses it. For
     * tests of what the SDK reports when there is no response to read. The
     * failure is Guzzle's own ConnectException, queued on the MockHandler, so
     * no socket is opened.
     *
     * $describe, when given, writes the error's message from the request, as a
     * client or middleware that quotes what it sent would. $status, when given,
     * makes the failure a RequestException holding an empty response with that
     * status, as middleware that throws on a status would.
     *
     * @param array<string, mixed>                       $config   overrides of the default options, as for
     *                                                             clientWith()
     * @param (\Closure(RequestInterface): string)|null $describe
     */
    protected function clientThatCannotConnect(array $config = [], ?\Closure $describe = null, ?int $status = null): Client
    {
        return $this->clientWhoseHttpClientThrows(
            $config,
            static fn(RequestInterface $request): TransferException => $status === null
                ? new ConnectException($describe === null ? 'Connection refused' : $describe($request), $request)
                : new RequestException($describe === null ? 'Server error' : $describe($request), $request, new Response($status)),
        );
    }

    /**
     * Build a Client whose HTTP client throws what $throw returns for the
     * request, as a handler or middleware that throws would: any Throwable, not
     * only one of Guzzle's.
     *
     * $clientDefaults are the Guzzle client's own default request options,
     * such as 'headers' or 'json', as an injected client may carry.
     *
     * @param array<string, mixed>                  $config         overrides of the default options, as for
     *                                                              clientWith()
     * @param \Closure(RequestInterface): \Throwable $throw
     * @param array<string, mixed>                  $clientDefaults
     */
    protected function clientWhoseHttpClientThrows(array $config, \Closure $throw, array $clientDefaults = []): Client
    {
        if (array_key_exists('http_client', $config)) {
            throw new \LogicException('the client owns its HTTP client');
        }
        // Returned, not thrown: MockHandler rejects the request with a
        // Throwable a queued callable returns, as a real handler does.
        $this->mock = new MockHandler([$throw]);

        return new Client(array_merge([
            'base_url' => 'https://octonomy.test',
            'token' => 'test-token',
            'tenant_id' => 'tenant-1',
            'http_client' => new GuzzleClient(['handler' => HandlerStack::create($this->mock)] + $clientDefaults),
        ], $config));
    }

    /**
     * Build a bare Transport over a MockHandler, for tests of the transport
     * contract itself rather than of a resource service. $apiVersion is the
     * surface it calls and the field set it hydrates, v2 by default as on a
     * Client.
     *
     * @param list<CapturedFixture|SyntheticNegative> $responses
     */
    protected function transportWith(array $responses, string $apiVersion = ApiVersion::V2): Transport
    {
        $transport = new Transport(new Config(
            baseUrl: 'https://octonomy.test',
            token: 'test-token',
            tenantId: 'tenant-1',
            httpClient: $this->mockedHttpClient($responses),
            apiVersion: $apiVersion,
        ), Client::VERSION);
        self::refuseCrossVersionReplay($transport->apiVersion(), $responses);

        return $transport;
    }

    /**
     * Assert that no request reached the HTTP stack: the SDK refused the call
     * before sending it.
     */
    protected function assertNothingSent(): void
    {
        self::assertNull($this->mock->getLastRequest(), 'expected the SDK to refuse the call before sending a request');
    }

    /**
     * A hydrator for Transport::one() and many() that returns each object as
     * array-mode json_decode() would, to compare with capturedData() in tests of
     * the envelope rather than of a model.
     *
     * @return \Closure(array<string, mixed>): array<array-key, mixed>
     */
    protected static function asDecoded(): \Closure
    {
        return static fn(array $members): array => self::toArrays($members);
    }

    /**
     * The "data" object of a single-resource response, as Transport hands it to
     * a model's fromArray(): its members, with nested objects still \stdClass.
     * For hydrating a model directly, outside a request.
     *
     * @return array<string, mixed>
     */
    protected static function members(CapturedFixture|SyntheticNegative $response): array
    {
        $decoded = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass || !$decoded->data instanceof \stdClass) {
            throw new \RuntimeException('expected a {"data": {...}} body');
        }

        $members = [];
        foreach (get_object_vars($decoded->data) as $key => $value) {
            $members[(string) $key] = $value;
        }

        return $members;
    }

    protected function lastRequest(): RequestInterface
    {
        $request = $this->mock->getLastRequest();
        if ($request === null) {
            self::fail('expected a request to have been sent');
        }

        return $request;
    }

    /**
     * Decode the JSON body of the last request.
     *
     * @return array<array-key, mixed>
     */
    protected function lastJsonBody(): array
    {
        $raw = (string) $this->lastRequest()->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::fail('request body was not a JSON object');
        }

        return $decoded;
    }

    /**
     * Parse the query string of the last request into an array.
     *
     * @return array<array-key, mixed>
     */
    protected function lastQuery(): array
    {
        $query = [];
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * A response a real Octonomy sent, replayed from tests/fixtures/captured, such
     * as captured('v1/tags/get'). Every response body a test needs comes from
     * here unless a conforming server could not produce it (syntheticNegative()).
     */
    protected static function captured(string $name): CapturedFixture
    {
        return CapturedFixture::load($name);
    }

    /**
     * The "data" member of a captured response, to assert what a model hydrated
     * against what the server actually sent.
     *
     * @return array<array-key, mixed>
     */
    protected static function capturedData(string $name): array
    {
        return CapturedFixture::load($name)->data();
    }

    /**
     * A response no conforming Octonomy sends, such as
     * syntheticNegative(SyntheticNegative::MISSING_ENVELOPE, 200, '[]'). These
     * are the one carve-out from captured fixtures (AGENTS.md), since a real
     * server cannot be made to produce them; $kind names which carve-out applies.
     *
     * @param array<string, string> $headers
     */
    protected static function syntheticNegative(
        string $kind,
        int $status,
        string $body = '',
        array $headers = ['Content-Type' => 'application/json'],
    ): SyntheticNegative {
        return new SyntheticNegative($kind, $status, $body, $headers);
    }

    /**
     * A captured response with its decoded body changed by $break, as the
     * synthetic negative $kind, such as a captured tag with one field removed.
     * This is how a negative differs from what the server sent in exactly the
     * one respect its kind names, while everything else stays captured.
     *
     * $break edits the body as arrays. An object that would not survive that
     * round trip as an object, {} or one keyed "0", "1"..., is handed over as
     * the \stdClass it decoded to, so it is re-encoded as the object the server
     * sent rather than as a list.
     *
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $break
     */
    protected static function syntheticNegativeFrom(string $name, string $kind, \Closure $break): SyntheticNegative
    {
        $captured = CapturedFixture::load($name);
        $body = self::editable(json_decode($captured->body, false, 512, JSON_THROW_ON_ERROR));
        if (!is_array($body)) {
            throw new \LogicException("{$name} has no JSON object body to break");
        }

        return new SyntheticNegative(
            $kind,
            $captured->status,
            json_encode($break($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            $captured->headers,
            $captured->apiVersion(),
        );
    }

    /**
     * Refuse to replay a response on a surface it was not captured from. A body
     * captured from one api_version is not a capture of the other's response
     * (AGENTS.md), and an error or 204 replayed across would pass silently, since
     * neither is hydrated. A synthetic negative written from nothing names no
     * api_version and fits either.
     *
     * @param list<CapturedFixture|SyntheticNegative> $responses
     */
    private static function refuseCrossVersionReplay(string $apiVersion, array $responses): void
    {
        foreach ($responses as $response) {
            $from = $response->apiVersion();
            if ($from !== null && $from !== $apiVersion) {
                throw new \LogicException(sprintf(
                    'a response captured from api_version %s cannot be replayed on a %s client; capture or pick a %s fixture, or set api_version to %s',
                    $from,
                    $apiVersion,
                    $apiVersion,
                    $from,
                ));
            }
        }
    }

    /**
     * A decoded body with each object turned into an array a closure can edit,
     * except an object that would re-encode as a list (see syntheticNegativeFrom).
     */
    private static function editable(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            if ($members === [] || array_is_list($members)) {
                return $value;
            }
            $value = $members;
        }

        return is_array($value) ? array_map(self::editable(...), $value) : $value;
    }

    /**
     * $value with every nested \stdClass turned into an array.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function toArrays(array $value): array
    {
        $decoded = json_decode(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<CapturedFixture|SyntheticNegative> $responses
     */
    private function mockedHttpClient(array $responses): GuzzleClient
    {
        // Checked here rather than left to the closure's union type below: the PHP 7.4
        // downgrade deletes union types, and the 7.4 line must refuse the same inputs (#10).
        foreach ($responses as $response) {
            if (!$response instanceof CapturedFixture && !$response instanceof SyntheticNegative) {
                throw new \TypeError(sprintf(
                    'responses must come from captured() or syntheticNegative(), got %s',
                    is_object($response) ? $response::class : gettype($response),
                ));
            }
        }

        $this->mock = new MockHandler(array_map(
            static fn(CapturedFixture|SyntheticNegative $response): Response => $response->response(),
            $responses,
        ));

        return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
    }
}
