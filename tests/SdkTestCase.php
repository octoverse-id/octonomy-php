<?php

declare(strict_types=1);

namespace Octoverse\Octonomy\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Octoverse\Octonomy\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * SdkTestCase wires a Client to a Guzzle MockHandler so tests can queue canned
 * responses and inspect the request the SDK actually sent.
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
     * @param array<string, mixed> $data
     */
    protected static function jsonResponse(int $status, array $data): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
