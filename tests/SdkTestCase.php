<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use GuzzleHttp\Client as GuzzleClient;
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

        return new Client(array_merge([
            'base_url' => 'https://octonomy.test',
            'token' => 'test-token',
            'tenant_id' => 'tenant-1',
            'http_client' => $this->mockedHttpClient($responses),
        ], $config));
    }

    /**
     * Build a bare Transport over a MockHandler, for tests of the transport
     * contract itself rather than of a resource service. $apiVersion is the
     * surface it calls and the field set it hydrates; Client offers only v1 until
     * the api_version option lands (#15).
     *
     * @param list<CapturedFixture|SyntheticNegative> $responses
     */
    protected function transportWith(array $responses, string $apiVersion = ApiVersion::V1): Transport
    {
        return new Transport(new Config(
            baseUrl: 'https://octonomy.test',
            token: 'test-token',
            tenantId: 'tenant-1',
            httpClient: $this->mockedHttpClient($responses),
        ), Client::VERSION, $apiVersion);
    }

    /**
     * A hydrator for Transport::one() and many() that returns each object as it
     * was decoded, for tests of the envelope rather than of a model.
     *
     * @return \Closure(array<string, mixed>): array<string, mixed>
     */
    protected static function asDecoded(): \Closure
    {
        return static fn(array $object): array => $object;
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
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $break
     */
    protected static function syntheticNegativeFrom(string $name, string $kind, \Closure $break): SyntheticNegative
    {
        $captured = CapturedFixture::load($name);

        return self::syntheticNegative(
            $kind,
            $captured->status,
            json_encode($break($captured->json()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            $captured->headers,
        );
    }

    /**
     * @param list<CapturedFixture|SyntheticNegative> $responses
     */
    private function mockedHttpClient(array $responses): GuzzleClient
    {
        $this->mock = new MockHandler(array_map(
            static fn(CapturedFixture|SyntheticNegative $response): Response => $response->response(),
            $responses,
        ));

        return new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);
    }
}
