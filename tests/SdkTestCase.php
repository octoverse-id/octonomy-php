<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Octoverse\Octonomy\Client;
use Octoverse\Octonomy\Config;
use Octoverse\Octonomy\Http\Transport;
use Octoverse\Octonomy\Tests\Fixture\CapturedFixture;
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
     * @param list<Response> $responses
     * @param array<string, mixed> $config
     */
    protected function clientWith(array $responses, array $config = []): Client
    {
        $this->mock = new MockHandler($responses);
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create($this->mock)]);

        return new Client(array_merge([
            'base_url' => 'https://octonomy.test',
            'token' => 'test-token',
            'tenant_id' => 'tenant-1',
            'http_client' => $guzzle,
        ], $config));
    }

    /**
     * Build a bare Transport over a MockHandler, for tests of the transport
     * contract itself rather than of a resource service.
     *
     * @param list<Response> $responses
     */
    protected function transportWith(array $responses): Transport
    {
        $this->mock = new MockHandler($responses);

        return new Transport(new Config(
            baseUrl: 'https://octonomy.test',
            token: 'test-token',
            tenantId: 'tenant-1',
            httpClient: new GuzzleClient(['handler' => HandlerStack::create($this->mock)]),
        ), Client::VERSION);
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
    protected static function captured(string $name): Response
    {
        return CapturedFixture::load($name)->response();
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
     * A response no conforming Octonomy sends: a missing envelope or field, a
     * wrong type, invalid JSON, a gateway's HTML. These are the one carve-out
     * from captured fixtures (AGENTS.md), since a real server cannot be made to
     * produce them; the name marks each one at its call site.
     *
     * @param array<string, string> $headers
     */
    protected static function syntheticNegative(
        int $status,
        string $body = '',
        array $headers = ['Content-Type' => 'application/json'],
    ): Response {
        return new Response($status, $headers, $body);
    }
}
